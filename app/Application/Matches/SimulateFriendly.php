<?php

namespace App\Application\Matches;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V5\Simulation as V5Simulation;
use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Models\MatchSimulation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SimulateFriendly
{
    public function __construct(private FootballMatchEngine $engine, private TeamSnapshotFactory $snapshots,
        private SquadSheet $sheets, private MatchAttendanceService $attendance) {}

    public function execute(User $user, Career $career, array $input): MatchSimulation
    {
        Gate::forUser($user)->authorize('manageSquad', $career);
        $input['mode'] ??= 'user_vs_ai';
        $data = Validator::make($input, [
            'mode' => ['required', 'in:user_vs_ai,ai_vs_ai'],
            'home_club_id' => ['exclude_unless:mode,ai_vs_ai', 'required', 'integer'],
            'away_club_id' => ['required', 'integer'],
            'seed' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/D'],
            'request_id' => ['required', 'uuid'],
        ])->validate();
        $requestHash = $data['mode'] === 'user_vs_ai'
            ? ReplayHash::of([(int) $data['away_club_id'], $data['seed']])
            : ReplayHash::of(['ai_vs_ai', (int) $data['home_club_id'], (int) $data['away_club_id'], $data['seed']]);

        return DB::transaction(function () use ($career, $user, $data, $requestHash): MatchSimulation {
            $career = Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('manageSquad', $career);
            $existing = MatchSimulation::where('career_id', $career->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ($existing->request_checksum !== $requestHash) {
                    throw ValidationException::withMessages(['request_id' => 'A solicitação já foi usada com outros dados.']);
                }

                return $existing;
            }
            if (! $this->sheets->editable($career)) {
                throw ValidationException::withMessages(['match' => 'Aguarde a conclusão da operação atual da carreira.']);
            }
            $aiOnly = $data['mode'] === 'ai_vs_ai';
            $home = $aiOnly ? $career->clubs()->whereKey($data['home_club_id'])->first() : $career->controlledClub->club;
            $aiIds = \App\Domain\Career\Models\CareerClubController::where('career_id', $career->id)->where('type', 'ai')->pluck('career_club_id')->all();
            if (! $home || ($aiOnly && ! in_array($home->id, $aiIds, true))) {
                throw ValidationException::withMessages(['home_club_id' => 'Selecione um mandante controlado pela IA nesta carreira.']);
            }
            $away = $career->clubs()->whereKey($data['away_club_id'])->where('id', '!=', $home->id)->first();
            if (! $away || ! in_array($away->id, $aiIds, true)) {
                throw ValidationException::withMessages(['away_club_id' => 'Selecione outro clube desta carreira.']);
            }
            try {
                $attendancePlan = $this->attendance->forecast($career, $home, $away);
                $realized = $this->attendance->realize($attendancePlan, $career->creation_key.'|friendly|'.$data['seed']);
                $rules = CompetitionEdition::where('career_id', $career->id)
                    ->whereIn('status', ['scheduled', 'active'])->latest('id')->first()?->rules ?? [];
                $engineVersion = $rules['match_engine_version'] ?? V5Simulation::VERSION;
                $snapshot = (new MatchInput([
                    'seed' => $data['seed'], 'engine_version' => $engineVersion,
                    'rules' => [
                        'max_substitutions' => (int) ($rules['max_substitutions'] ?? 3),
                        'max_substitution_windows' => (int) ($rules['max_substitution_windows'] ?? 3),
                        'hydration_breaks_enabled' => (bool) ($rules['hydration_breaks_enabled'] ?? false),
                        'dynamic_added_time_enabled' => (bool) ($rules['dynamic_added_time_enabled'] ?? false),
                        'added_time_rules' => $rules['added_time_rules'] ?? AddedTimeRules::DEFAULTS,
                    ],
                    'home' => $this->snapshots->make($career, $home, ! $aiOnly, $away, true),
                    'away' => $this->snapshots->make($career, $away, false, $home, false),
                    'home_context' => $this->attendance->homeContext($attendancePlan, $realized),
                ]))->data;
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['lineup' => $exception->getMessage()]);
            }
            $result = $this->engine->simulate($snapshot);

            return MatchSimulation::create([
                'career_id' => $career->id, 'home_club_id' => $home->id, 'away_club_id' => $away->id,
                'request_id' => $data['request_id'], 'request_checksum' => $requestHash,
                'seed' => $snapshot['seed'], 'engine_version' => $snapshot['engine_version'],
                'attendance_plan' => $attendancePlan,
                'input' => $snapshot, 'result' => $result,
                'input_checksum' => ReplayHash::of($snapshot), 'result_checksum' => ReplayHash::of($result),
            ]);
        }, 3);
    }
}
