<?php

namespace App\Application\Careers;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Competitions\StandingsService;
use App\Application\Matches\PlayOfficialMatch;
use App\Application\Matches\TeamSnapshotFactory;
use App\Application\Stadiums\StadiumProjectService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerEvent;
use App\Domain\Career\Models\CareerOperation;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Career\Models\StadiumProject;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class CareerProgressionService
{
    public const ADVANCE_ONE_DAY = 'ADVANCE_ONE_DAY';

    public const ADVANCE_TO_NEXT_MATCH = 'ADVANCE_TO_NEXT_MATCH';

    public const ADVANCE_TO_NEXT_EVENT = 'ADVANCE_TO_NEXT_EVENT';

    public const PLAY_MATCH = 'PLAY_MATCH';

    public const ACKNOWLEDGE_EVENT = 'ACKNOWLEDGE_EVENT';

    public const START_NEXT_SEASON = 'START_NEXT_SEASON';

    public function __construct(private CareerCalendar $calendar, private TeamSnapshotFactory $snapshots,
        private PlayOfficialMatch $matches, private StandingsService $standings,
        private MatchAttendanceService $attendance, private StadiumProjectService $projects,
        private SeasonTransitionService $seasons) {}

    public function execute(User $user, Career $career, array $input): CareerOperation
    {
        // Reservation is durable before processing: a killed request can be resumed explicitly.
        $operation = $this->authorizeOperation($user, $career, $input);

        return $this->resume($user, $career, $operation);
    }

    public function authorizeOperation(User $user, Career $career, array $input): CareerOperation
    {
        Gate::forUser($user)->authorize('manageSquad', $career);
        $data = Validator::make($input, [
            'action' => ['required', Rule::in([self::ADVANCE_ONE_DAY, self::ADVANCE_TO_NEXT_MATCH, self::ADVANCE_TO_NEXT_EVENT, self::PLAY_MATCH, self::ACKNOWLEDGE_EVENT, self::START_NEXT_SEASON])],
            'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0'],
            'match_id' => ['exclude_unless:action,PLAY_MATCH', 'required', 'integer'],
            'event_id' => ['exclude_unless:action,ACKNOWLEDGE_EVENT', 'required', 'integer'],
        ])->validate();
        $checksum = ReplayHash::of([$data['action'], (int) $data['revision'], (int) ($data['match_id'] ?? 0), (int) ($data['event_id'] ?? 0)]);

        return DB::transaction(function () use ($user, $career, $data, $checksum) {
            $career = $this->lock($career);
            Gate::forUser($user)->authorize('manageSquad', $career);
            $existing = CareerOperation::where('career_id', $career->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_checksum, $checksum)) {
                    $this->invalid('request_id', 'Esta solicitação já foi usada com outros dados.');
                }

                return $existing;
            }
            if ($career->state === CareerState::Processing || CareerOperation::where('career_id', $career->id)->where('status', 'pending')->exists()) {
                $this->invalid('progression', 'Há uma operação autorizada pendente. Retome-a antes de continuar.');
            }
            if ($career->progression_revision !== (int) $data['revision']) {
                $this->invalid('revision', 'A carreira mudou em outra aba. Recarregue a página antes de continuar.');
            }
            $target = $career->current_date;
            $stored = $data;
            $stored['engine_version'] = \App\Domain\Football\Engine\FootballMatchEngine::VERSION;
            if ($data['action'] === self::START_NEXT_SEASON) {
                $season = $this->calendar->season($career);
                if ($career->state !== CareerState::AwaitingSeason || $season->status !== 'completed' || ! $season->ended_on) {
                    $this->invalid('progression', 'Encerre a temporada atual antes de iniciar a próxima.');
                }
                $target = $season->ended_on->addDays((int) config('careers.offseason_days'));
                $stored['next_season_number'] = $season->number + 1;
            } else {
                if ($career->state === CareerState::AwaitingSeason) {
                    $this->invalid('progression', 'A temporada está encerrada. Confirme o início da próxima temporada.');
                }
                $this->calendar->scheduleEnd($career);
                $match = $this->calendar->nextMatch($career);
                $decision = $this->calendar->decision($career);
                if ($data['action'] === self::ACKNOWLEDGE_EVENT) {
                    if (! $decision || $decision->id !== (int) $data['event_id'] || $decision->kind !== 'administrative_decision') {
                        $this->invalid('event_id', 'Selecione o acontecimento administrativo pendente desta carreira.');
                    }
                } else {
                    if ($decision) {
                        $this->invalid('progression', 'Confirme o acontecimento pendente antes de continuar.');
                    }
                    if ($data['action'] === self::PLAY_MATCH) {
                        if (! $match || $match->id !== (int) $data['match_id'] || ! $match->scheduled_on->eq($career->current_date) || $career->state !== CareerState::PreMatch) {
                            $this->invalid('match_id', 'Só é possível confirmar a próxima partida no pré-jogo da data atual.');
                        }
                        $human = $career->controlledClub->club;
                        $opponent = $match->home_club_id === $human->id ? $match->awayClub : $match->homeClub;
                        $interactive = InteractiveMatchSession::where('career_id', $career->id)->where('match_id', $match->id)
                            ->where('status', 'finished')->first();
                        if ($interactive) {
                            $side = $interactive->meta['human_side'];
                            $stored['interactive_session_id'] = $interactive->id;
                            $stored['human_team'] = $interactive->input[$side];
                            $stored['human_attendance_plan'] = $interactive->meta['attendance_plan'];
                        } else {
                            // Validate and freeze the user's final decisions before reserving the operation.
                            $stored['human_team'] = $this->snapshots->make($career, $human, true, $opponent, $match->home_club_id === $human->id);
                            $stored['human_attendance_plan'] = $this->attendance->forecast($career, $match->homeClub, $match->awayClub, $match);
                        }
                    } else {
                        if ($match && $match->scheduled_on->lte($career->current_date)) {
                            $this->invalid('progression', 'Jogue a partida pendente antes de avançar o calendário.');
                        }
                        $event = $this->calendar->nextEvent($career);
                        $due = $data['action'] === self::ADVANCE_TO_NEXT_EVENT
                            ? StadiumProject::where('career_id', $career->id)->where('career_club_id', $career->controlledClub->career_club_id)->where('status', 'active')
                                ->where('due_on', '>=', $career->current_date->toDateString())->min('due_on')
                            : null;
                        $target = match ($data['action']) {
                            self::ADVANCE_ONE_DAY => $career->current_date->addDay(),
                            self::ADVANCE_TO_NEXT_MATCH => $match?->scheduled_on ?? $event?->scheduled_on,
                            self::ADVANCE_TO_NEXT_EVENT => $this->earliest($this->earliest($match?->scheduled_on, $event?->scheduled_on), $due ? CarbonImmutable::parse($due) : null),
                        };
                        if (! $target || $target->lt($career->current_date) || $career->current_date->diffInDays($target) > 3660) {
                            $this->invalid('progression', 'Não há um destino válido no calendário desta temporada.');
                        }
                    }
                }
            }
            $operation = CareerOperation::create([
                'career_id' => $career->id, 'request_id' => $data['request_id'], 'request_checksum' => $checksum,
                'kind' => $data['action'], 'status' => 'pending', 'from_date' => $career->current_date,
                'from_state' => $career->state->value, 'target_date' => $target, 'input' => $stored,
            ]);
            $career->update(['state' => CareerState::Processing]);

            return $operation;
        }, 5);
    }

    public function resume(User $user, Career $career, CareerOperation $operation): CareerOperation
    {
        Gate::forUser($user)->authorize('manageSquad', $career);
        abort_unless($operation->career_id === $career->id, 404);

        return DB::transaction(function () use ($user, $career, $operation) {
            $career = $this->lock($career);
            Gate::forUser($user)->authorize('manageSquad', $career);
            $operation = CareerOperation::where('career_id', $career->id)->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($operation->status === 'completed') {
                return $operation;
            }
            if ($career->state !== CareerState::Processing || ! $career->current_date->eq($operation->from_date)
                || $career->progression_revision !== (int) $operation->input['revision']) {
                throw new LogicException('A operação não corresponde ao estado reservado da carreira.');
            }
            $played = [];
            $nextState = CareerState::AwaitingDecision;
            if ($operation->kind === self::START_NEXT_SEASON) {
                $this->seasons->execute($career);
            } elseif ($operation->kind === self::PLAY_MATCH) {
                foreach ($career->matches()->where('status', 'scheduled')->onDate($career->current_date)->orderBy('id')->get() as $match) {
                    $played[] = $this->matches->execute($career, $match, $operation)->match_id;
                }
            } elseif ($operation->kind === self::ACKNOWLEDGE_EVENT) {
                CareerEvent::where('career_id', $career->id)->whereKey($operation->input['event_id'])->where('status', 'pending')
                    ->update(['status' => 'processed', 'processed_on' => $career->current_date->toDateString()]);
                if ($this->calendar->nextMatch($career)?->scheduled_on->eq($career->current_date)) {
                    $nextState = CareerState::PreMatch;
                }
            } else {
                // Each confirmed day is committed together with all its effects, never by the real clock.
                $nextState = $this->processDate($career, $operation, $played);
                while ($nextState === CareerState::AwaitingDecision && ! $this->calendar->decision($career) && $career->current_date->lt($operation->target_date)) {
                    $career->current_date = $career->current_date->addDay();
                    $career->save();
                    $this->recoverPlayers($career, $operation);
                    $nextState = $this->processDate($career, $operation, $played);
                    if ($nextState !== CareerState::AwaitingDecision || $this->calendar->decision($career)) {
                        break;
                    }
                }
            }
            $career->update(['state' => $nextState, 'progression_revision' => $career->progression_revision + 1]);
            $operation->update(['status' => 'completed', 'result' => [
                'current_date' => $career->current_date->toDateString(), 'state' => $nextState->value,
                'revision' => $career->progression_revision, 'played_match_ids' => $played,
            ]]);
            if (isset($operation->input['interactive_session_id'])) {
                InteractiveMatchSession::where('career_id', $career->id)->whereKey($operation->input['interactive_session_id'])
                    ->where('status', 'finished')->update(['status' => 'finalized']);
            }

            return $operation;
        }, 5);
    }

    private function processDate(Career $career, CareerOperation $operation, array &$played): CareerState
    {
        $this->projects->completeDue($career);
        $this->projects->planForAi($career);
        if ($this->calendar->decision($career)) {
            return CareerState::AwaitingDecision;
        }
        $humanMatch = $this->calendar->nextMatch($career);
        if ($humanMatch && $humanMatch->scheduled_on->lte($career->current_date)) {
            return CareerState::PreMatch;
        }
        foreach ($career->matches()->where('status', 'scheduled')->onDate($career->current_date)->orderBy('id')->get() as $match) {
            $played[] = $this->matches->execute($career, $match, $operation)->match_id;
        }
        foreach (CareerEvent::where('career_id', $career->id)->where('status', 'pending')->where('scheduled_on', '<', $career->current_date->addDay()->toDateString())
            ->orderBy('scheduled_on')->orderByRaw("CASE WHEN kind = 'season_end' THEN 1 ELSE 0 END")->orderBy('id')->get() as $event) {
            if ($event->kind === 'season_end') {
                $this->finishSeason($career, $event);
                $event->update(['status' => 'processed', 'processed_on' => $career->current_date]);

                return CareerState::AwaitingSeason;
            }
            if ($event->kind !== 'notice') {
                throw new LogicException('Acontecimento sem processador: '.$event->kind);
            }
            $event->update(['status' => 'processed', 'processed_on' => $career->current_date]);
        }

        return CareerState::AwaitingDecision;
    }

    private function recoverPlayers(Career $career, CareerOperation $operation): void
    {
        DB::table('career_processed_days')->insert([
            'career_id' => $career->id, 'career_operation_id' => $operation->id,
            'processed_on' => $career->current_date->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $amount = max(0, min(100, (int) config('careers.daily_fitness_recovery', 8)));
        if ($amount > 0) {
            $career->players()->whereNull('retired_on')->where('fitness', '<', 100)
                ->where(fn ($q) => $q->whereNull('injured_until')->orWhere('injured_until', '<', $career->current_date->toDateString()))
                ->update(['fitness' => DB::raw("CASE WHEN fitness + {$amount} > 100 THEN 100 ELSE fitness + {$amount} END")]);
        }
    }

    private function finishSeason(Career $career, CareerEvent $event): void
    {
        $season = $this->calendar->season($career);
        if ($event->career_season_id !== $season->id || $season->status !== 'active') {
            throw new LogicException('Encerramento incompatível com a temporada ativa.');
        }
        $editions = CompetitionEdition::where('career_id', $career->id)->where('career_season_id', $season->id)->get();
        $summary = [];
        foreach ($editions as $edition) {
            $matches = $career->matches()->where('competition_edition_id', $edition->id);
            if ((clone $matches)->count() !== (int) $edition->rules['match_count'] || (clone $matches)->where('status', '!=', 'played')->exists()
                || (clone $matches)->whereDoesntHave('result')->exists()) {
                throw new LogicException('A temporada só pode encerrar com todos os resultados oficiais consolidados.');
            }
            $table = $this->standings->standings($edition);
            $summary[] = ['edition_id' => $edition->id, 'name' => $edition->name, 'champion_club_id' => $table[0]['club_id'],
                'standings' => $table, 'scorers' => $this->standings->scorers($edition)];
            $edition->update(['status' => 'completed']);
        }
        $season->update(['status' => 'completed', 'ended_on' => $career->current_date, 'summary' => $summary]);
    }

    private function lock(Career $career): Career
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no row locks. Acquire its writer lock before reading the revision.
            DB::table('careers')->where('id', $career->id)->update(['progression_revision' => DB::raw('progression_revision')]);
        }

        return Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
    }

    private function earliest(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null ? $b : ($b === null || $a->lte($b) ? $a : $b);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
