<?php

namespace App\Application\Matches;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Finance\TicketPricingService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V5\Simulation as V5Simulation;
use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Engine\V6\Simulation as V6Simulation;
use App\Domain\Football\Models\InteractiveMatchRequest;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InteractiveMatchService
{
    public function __construct(private TeamSnapshotFactory $snapshots, private MatchAttendanceService $attendance,
        private TicketPricingService $tickets, private FootballMatchEngine $engine) {}

    public function start(User $user, Career $career, CareerMatch $match): InteractiveMatchSession
    {
        Gate::forUser($user)->authorize('manageSquad', $career);

        return DB::transaction(function () use ($user, $career, $match): InteractiveMatchSession {
            $career = Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('manageSquad', $career);
            $match = CareerMatch::where('career_id', $career->id)->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $existing = InteractiveMatchSession::where('career_id', $career->id)->where('match_id', $match->id)->first();
            if ($existing) {
                return $existing;
            }
            if ($career->state !== CareerState::PreMatch || ! $match->scheduled_on->eq($career->current_date)
                || $match->status !== 'scheduled' || ! in_array($career->controlledClub->career_club_id, [$match->home_club_id, $match->away_club_id], true)) {
                $this->invalid('match', 'A partida interativa só pode começar no pré-jogo atual.');
            }

            $humanId = $career->controlledClub->career_club_id;
            $teams = [];
            foreach (['home' => [$match->homeClub, $match->awayClub], 'away' => [$match->awayClub, $match->homeClub]] as $side => [$club, $opponent]) {
                $teams[$side] = $club->id === $humanId
                    ? $this->snapshots->make($career, $club, true, $opponent, $side === 'home')
                    : $this->snapshots->make($career, $club, false, $opponent, $side === 'home');
            }
            $plan = $this->attendance->forecast($career, $match->homeClub, $match->awayClub, $match);
            if ($match->home_club_id !== $humanId) {
                $plan = $this->tickets->chooseForAi($plan);
            }
            $realized = $this->attendance->realize($plan, $career->creation_key.'|'.$match->id);
            $rules = CompetitionEdition::where('career_id', $career->id)->whereKey($match->competition_edition_id)->firstOrFail()->rules;
            $engineVersion = $rules['match_engine_version'] ?? V5Simulation::VERSION;
            $input = (new MatchInput([
                'engine_version' => $engineVersion,
                'seed' => hash('sha256', ($engineVersion === V6Simulation::VERSION ? 'official-v6|' : 'official-v4|').$career->creation_key.'|'.$match->id),
                'rules' => [
                    'max_substitutions' => (int) ($rules['max_substitutions'] ?? 3),
                    'max_substitution_windows' => (int) ($rules['max_substitution_windows'] ?? 3),
                    'hydration_breaks_enabled' => (bool) ($rules['hydration_breaks_enabled'] ?? false),
                    'dynamic_added_time_enabled' => (bool) ($rules['dynamic_added_time_enabled'] ?? false),
                    'added_time_rules' => $rules['added_time_rules'] ?? AddedTimeRules::DEFAULTS,
                ],
                'home' => $teams['home'], 'away' => $teams['away'],
                'home_context' => $this->attendance->homeContext($plan, $realized), 'decisions' => [],
            ]))->data;
            $simulation = $this->simulation($input);
            $state = $simulation->state();

            return InteractiveMatchSession::create([
                'career_id' => $career->id, 'match_id' => $match->id, 'engine_version' => $engineVersion,
                'status' => 'running', 'paused' => true, 'minute' => 0, 'revision' => 0,
                'input' => $input, 'state' => $state,
                'meta' => ['human_side' => $match->home_club_id === $humanId ? 'home' : 'away', 'attendance_plan' => $plan, 'attendance_realized' => $realized],
            ]);
        }, 5);
    }

    public function find(User $user, Career $career, CareerMatch $match): InteractiveMatchSession
    {
        Gate::forUser($user)->authorize('manageSquad', $career);

        return InteractiveMatchSession::where('career_id', $career->id)->where('match_id', $match->id)->firstOrFail();
    }

    public function control(User $user, Career $career, InteractiveMatchSession $session, array $data): array
    {
        return $this->mutate($user, $career, $session, 'control', $data, function (InteractiveMatchSession $locked) use ($data): void {
            $action = $data['action'];
            $simulation = $this->simulation($locked->input, $locked->state);
            if ($action === 'pause') {
                $locked->paused = true;
            } elseif ($action === 'resume') {
                if ($locked->state['status'] === 'half_time') {
                    $simulation->resumeHalfTime();
                    $locked->state = $simulation->state();
                } elseif ($locked->state['status'] === 'hydration_break') {
                    $simulation->resumeHydrationBreak();
                    $locked->state = $simulation->state();
                }
                $locked->paused = false;
            } else {
                $this->invalid('action', 'Controle de reprodução inválido.');
            }
        });
    }

    public function advance(User $user, Career $career, InteractiveMatchSession $session, array $data): array
    {
        return $this->mutate($user, $career, $session, 'advance', $data, function (InteractiveMatchSession $locked) use ($data): void {
            if ($locked->paused) {
                $this->invalid('match', 'Continue a partida antes de solicitar o próximo minuto.');
            }
            $simulation = $this->simulation($locked->input, $locked->state);
            $state = $simulation->advance((int) ($data['minutes'] ?? 1));
            $locked->state = $state;
            $locked->minute = $state['tick'];
            if (in_array($state['status'], ['half_time', 'hydration_break'], true)) {
                $locked->paused = true;
            }
            if (in_array($state['status'], ['finished', 'abandoned'], true)) {
                $this->complete($locked, $simulation);
            }
        });
    }

    public function decide(User $user, Career $career, InteractiveMatchSession $session, array $data): array
    {
        return $this->mutate($user, $career, $session, 'decision', $data, function (InteractiveMatchSession $locked) use ($data): void {
            if (! $locked->paused) {
                $this->invalid('match', 'Pause a partida antes de alterar a equipe.');
            }
            $side = $locked->meta['human_side'];
            $simulation = $this->simulation($locked->input, $locked->state);
            $locked->state = $simulation->applyDecision($side, $data['type'], (array) ($data['payload'] ?? []));
            $locked->minute = $locked->state['tick'];
        });
    }

    public function finish(User $user, Career $career, InteractiveMatchSession $session, array $data): array
    {
        return $this->mutate($user, $career, $session, 'finish', $data, function (InteractiveMatchSession $locked): void {
            $simulation = $this->simulation($locked->input, $locked->state);
            while (! in_array($simulation->state()['status'], ['finished', 'abandoned'], true)) {
                $simulation->advance(120, true);
            }
            $locked->state = $simulation->state();
            $locked->minute = $locked->state['tick'];
            $this->complete($locked, $simulation);
        });
    }

    public function response(InteractiveMatchSession $session): array
    {
        return [
            'id' => $session->id, 'status' => $session->status, 'paused' => $session->paused,
            'minute' => $session->minute, 'revision' => $session->revision,
            'state' => $session->state, 'result' => $session->result,
        ];
    }

    private function complete(InteractiveMatchSession $session, V5Simulation|V6Simulation $simulation): void
    {
        $state = $simulation->state();
        $humanDecisions = array_values(array_filter($state['decisions'], fn (array $decision): bool => $decision['actor'] === 'human' && in_array($decision['side'], ['home', 'away'], true)));
        $input = $session->input;
        $input['decisions'] = array_map(fn (array $decision): array => [
            'tick' => $decision['tick'], 'minute' => $decision['minute'], 'added_minute' => $decision['added_minute'],
            'side' => $decision['side'], 'type' => $decision['type'], 'payload' => $decision['payload'],
        ], $humanDecisions);
        $result = $simulation->result();
        if (ReplayHash::of($result) !== ReplayHash::of($this->engine->simulate($input))) {
            throw new \LogicException('A reprodução incremental divergiu do resultado confirmado.');
        }
        $session->input = $input;
        $session->result = $result;
        $session->status = 'finished';
        $session->paused = true;
    }

    private function simulation(array $input, ?array $state = null): V5Simulation|V6Simulation
    {
        return match ($input['engine_version']) {
            V5Simulation::VERSION => new V5Simulation($input, $state),
            V6Simulation::VERSION => new V6Simulation($input, $state),
            default => throw new \LogicException('Versão incremental não suportada.'),
        };
    }

    private function mutate(User $user, Career $career, InteractiveMatchSession $session, string $kind, array $data, callable $callback): array
    {
        Gate::forUser($user)->authorize('manageSquad', $career);
        $checksum = ReplayHash::of([$kind, (int) $data['revision'], $data['action'] ?? null, $data['type'] ?? null, $data['payload'] ?? null, $data['minutes'] ?? null]);

        return DB::transaction(function () use ($user, $career, $session, $kind, $data, $callback, $checksum): array {
            $career = Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('manageSquad', $career);
            $locked = InteractiveMatchSession::where('career_id', $career->id)->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $existing = InteractiveMatchRequest::where('interactive_match_session_id', $locked->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_checksum, $checksum)) {
                    $this->invalid('request_id', 'Esta solicitação já foi usada com outros dados.');
                }

                return $existing->response;
            }
            if ($locked->status !== 'running' || $locked->revision !== (int) $data['revision']) {
                $this->invalid('revision', 'A partida mudou em outra aba. Recarregue antes de continuar.');
            }
            $callback($locked);
            $locked->revision++;
            $locked->save();
            $response = $this->response($locked);
            InteractiveMatchRequest::create([
                'interactive_match_session_id' => $locked->id, 'career_id' => $career->id,
                'request_id' => $data['request_id'], 'request_checksum' => $checksum, 'kind' => $kind,
                'result_revision' => $locked->revision, 'response' => $response,
            ]);

            return $response;
        }, 5);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
