<?php

namespace App\Application\Matches;

use App\Application\Attendance\FanEngagementService;
use App\Application\Attendance\MatchAttendanceService;
use App\Application\Finance\MatchFinanceService;
use App\Application\Finance\TicketPricingService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Career\Models\CareerOperation;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V5\Simulation as V5Simulation;
use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Engine\V6\Simulation as V6Simulation;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\Football\Models\OfficialMatchResult;
use LogicException;

/** Internal step of a locked, authorized progression transaction. */
class PlayOfficialMatch
{
    public function __construct(private FootballMatchEngine $engine, private TeamSnapshotFactory $snapshots,
        private MatchAttendanceService $attendance, private FanEngagementService $fans,
        private MatchFinanceService $finance, private TicketPricingService $tickets) {}

    public function execute(Career $career, CareerMatch $match, CareerOperation $operation): OfficialMatchResult
    {
        if ($career->state !== CareerState::Processing || $operation->career_id !== $career->id || $operation->status !== 'pending'
            || $match->career_id !== $career->id || ! $match->scheduled_on->eq($career->current_date)) {
            throw new LogicException('A partida exige uma operação autorizada na sua data interna.');
        }
        $existing = $match->result;
        if ($existing) {
            return $existing;
        }
        if ($match->status !== 'scheduled') {
            throw new LogicException('A partida já foi encerrada.');
        }
        $humanId = $career->controlledClub->career_club_id;
        $home = $match->homeClub;
        $away = $match->awayClub;
        $competitionRules = \App\Domain\Career\Models\CompetitionEdition::where('career_id', $career->id)
            ->whereKey($match->competition_edition_id)->firstOrFail()->rules;
        $interactive = isset($operation->input['interactive_session_id']) && (int) ($operation->input['match_id'] ?? 0) === $match->id
            ? InteractiveMatchSession::where('career_id', $career->id)->where('match_id', $match->id)
                ->whereKey($operation->input['interactive_session_id'])->where('status', 'finished')->firstOrFail()
            : null;
        if ($interactive) {
            $input = (new MatchInput($interactive->input))->data;
            $result = $interactive->result;
            if (ReplayHash::of($result) !== ReplayHash::of($this->engine->simulate($input))) {
                throw new LogicException('O resultado interativo não corresponde à reprodução determinística.');
            }
            $plan = $interactive->meta['attendance_plan'];
            $realized = $interactive->meta['attendance_realized'];
        } else {
            $engineVersion = $competitionRules['match_engine_version'] ?? V5Simulation::VERSION;
            $teams = [];
            foreach (['home' => [$home, $away], 'away' => [$away, $home]] as $side => [$club, $opponent]) {
                if ($club->id === $humanId) {
                    if ($operation->kind !== 'PLAY_MATCH' || (int) $operation->input['match_id'] !== $match->id) {
                        throw new LogicException('A partida do usuário exige confirmação específica.');
                    }
                    $teams[$side] = $operation->input['human_team'];
                } else {
                    $teams[$side] = $this->snapshots->make($career, $club, false, $opponent, $side === 'home');
                }
            }
            $plan = $operation->kind === 'PLAY_MATCH' && (int) $operation->input['match_id'] === $match->id
                ? ($operation->input['human_attendance_plan'] ?? $this->attendance->forecast($career, $home, $away, $match))
                : $this->attendance->forecast($career, $home, $away, $match);
            if ($home->id !== $humanId) {
                $plan = $this->tickets->chooseForAi($plan);
            }
            $realized = $this->attendance->realize($plan, $career->creation_key.'|'.$match->id);
            $input = (new MatchInput([
                'engine_version' => $engineVersion,
                'seed' => hash('sha256', ($engineVersion === V6Simulation::VERSION ? 'official-v6|' : 'official-v1|').$career->creation_key.'|'.$match->id),
                'rules' => [
                    'max_substitutions' => (int) ($competitionRules['max_substitutions'] ?? 3),
                    'max_substitution_windows' => (int) ($competitionRules['max_substitution_windows'] ?? 3),
                    'hydration_breaks_enabled' => (bool) ($competitionRules['hydration_breaks_enabled'] ?? false),
                    'dynamic_added_time_enabled' => (bool) ($competitionRules['dynamic_added_time_enabled'] ?? false),
                    'added_time_rules' => $competitionRules['added_time_rules'] ?? AddedTimeRules::DEFAULTS,
                ],
                'home' => $teams['home'], 'away' => $teams['away'], 'home_context' => $this->attendance->homeContext($plan, $realized),
            ]))->data;
            $result = $this->engine->simulate($input);
        }
        $record = OfficialMatchResult::create([
            'career_id' => $career->id, 'match_id' => $match->id, 'career_operation_id' => $operation->id,
            'played_on' => $career->current_date, 'engine_version' => $input['engine_version'], 'seed' => $input['seed'],
            'home_goals' => $result['home']['stats']['goals'], 'away_goals' => $result['away']['stats']['goals'],
            'input' => $input, 'result' => $result, 'input_checksum' => ReplayHash::of($input), 'result_checksum' => ReplayHash::of($result),
        ]);
        $crowd = $this->attendance->record($career, $match, $plan, $realized);
        $this->finance->record($career, $match, $crowd);
        $this->fans->apply($home, $away, $result, $plan);
        // Existing bans are served by sitting out this club's official match, never by advancing a day.
        $career->players()->whereIn('career_club_id', [$home->id, $away->id])->where('suspended_matches', '>', 0)->decrement('suspended_matches');
        foreach (['home', 'away'] as $side) {
            foreach ($result[$side]['players'] as $stats) {
                if ($stats['minutes'] === 0 && $stats['red_cards'] === 0) {
                    continue;
                }
                $effects = [
                    'fitness' => $stats['final_fitness'], 'suspended_matches' => $stats['red_cards'] > 0 ? 1 : 0,
                ];
                if (($stats['injured'] ?? false) && ($stats['injury_days'] ?? 0) > 0) {
                    $effects['injured_until'] = $career->current_date->addDays((int) $stats['injury_days'])->toDateString();
                }
                $career->players()->where('career_club_id', $side === 'home' ? $home->id : $away->id)->whereKey($stats['player_id'])->update($effects);
            }
        }
        $match->update(['status' => 'played']);
        \App\Domain\Career\Models\CompetitionEdition::whereKey($match->competition_edition_id)->where('status', 'scheduled')->update(['status' => 'active']);

        return $record;
    }
}
