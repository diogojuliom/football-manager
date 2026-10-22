<?php

namespace App\Application\Attendance;

use App\Application\Competitions\StandingsService;
use App\Domain\Attendance\Models\MatchAttendance;
use App\Domain\Attendance\V1\DemandModel;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Finance\Models\MatchTicketPolicy;
use Illuminate\Support\Facades\DB;
use LogicException;

class MatchAttendanceService
{
    public function __construct(private DemandModel $model, private StandingsService $standings) {}

    public function forecast(Career $career, CareerClub $home, CareerClub $away, ?CareerMatch $match = null): array
    {
        if ($home->career_id !== $career->id || $away->career_id !== $career->id || $home->id === $away->id
            || ($match && ($match->career_id !== $career->id || $match->home_club_id !== $home->id || $match->away_club_id !== $away->id))) {
            throw new LogicException('Clubes ou partida fora da carreira.');
        }
        $home->loadMissing(['stadium', 'support']);
        $away->loadMissing('support');
        $stadium = $home->stadium;
        if (! $stadium || ! $home->support || ! $away->support || $stadium->available_capacity < 1
            || $stadium->available_capacity > $stadium->capacity) {
            throw new LogicException('Estádio ou torcida sem dados válidos.');
        }
        $specificPrice = $match ? MatchTicketPolicy::where('match_id', $match->id)->value('price_cents') : null;

        $ranking = [];
        $leaderPoints = 0;
        $roundCount = 18;
        if ($match) {
            $edition = CompetitionEdition::where('career_id', $career->id)->findOrFail($match->competition_edition_id);
            $roundCount = (int) $edition->rules['round_count'];
            foreach ($this->standings->standings($edition, $match->scheduled_on) as $index => $row) {
                $ranking[$row['club_id']] = ['rank' => $index + 1, 'points' => $row['points']];
                $leaderPoints = max($leaderPoints, $row['points']);
            }
        }
        $rules = config('attendance.v1');
        $codes = [$home->code, $away->code];
        sort($codes);
        $rivalry = false;
        foreach ($rules['rivalries'] as $pair) {
            sort($pair);
            if ($codes === $pair) {
                $rivalry = true;
                break;
            }
        }
        $input = [
            'capacity' => (int) $stadium->available_capacity,
            'potential_supporters' => (int) $home->support->potential_supporters,
            'loyalty' => (int) $home->support->loyalty,
            'engagement' => (int) $home->support->engagement,
            'infrastructure_level' => (int) $stadium->infrastructure_level,
            'price_cents' => $specificPrice === null ? (int) $home->default_ticket_price_cents : (int) $specificPrice,
            'fixed_cost_cents' => (int) $stadium->fixed_operating_cost_cents,
            'variable_cost_cents' => (int) $stadium->variable_cost_per_supporter_cents,
            'opponent_supporters' => (int) $away->support->potential_supporters,
            'recent_form' => $match ? $this->recentForm($career, $home, $match) : 0,
            'away_recent_form' => $match ? $this->recentForm($career, $away, $match) : 0,
            'round' => $match ? (int) $match->round : 1,
            'round_count' => $roundCount,
            'leader_points' => $leaderPoints,
            'home_points' => $ranking[$home->id]['points'] ?? 0,
            'away_points' => $ranking[$away->id]['points'] ?? 0,
            'home_rank' => $ranking[$home->id]['rank'] ?? 10,
            'away_rank' => $ranking[$away->id]['rank'] ?? 10,
            'rivalry' => $rivalry,
        ];

        return $this->model->forecast($input, $rules) + [
            'career_id' => $career->id, 'match_id' => $match?->id,
            'home_club_id' => $home->id, 'away_club_id' => $away->id,
            'stadium_name' => $stadium->name,
        ];
    }

    public function realize(array $plan, string $seed): int
    {
        return $this->model->realize($plan, $seed);
    }

    public function homeContext(array $plan, int $realized): array
    {
        return ['neutral' => false, 'attendance' => $realized,
            'capacity' => $plan['input']['capacity'], 'engagement' => $plan['input']['engagement'],
            'ticket_price_cents' => $plan['input']['price_cents']];
    }

    public function record(Career $career, CareerMatch $match, array $plan, int $realized): MatchAttendance
    {
        $capacity = $plan['input']['capacity'];
        if ($match->career_id !== $career->id || $plan['career_id'] !== $career->id || $plan['match_id'] !== $match->id
            || $plan['home_club_id'] !== $match->home_club_id || $plan['away_club_id'] !== $match->away_club_id
            || $capacity < 1 || $realized < 0 || $realized > $capacity) {
            throw new LogicException('Público incompatível com a partida oficial.');
        }

        return MatchAttendance::create([
            'career_id' => $career->id, 'match_id' => $match->id, 'home_club_id' => $match->home_club_id,
            'stadium_name' => $plan['stadium_name'], 'played_on' => $career->current_date,
            'capacity' => $capacity, 'expected_demand' => $plan['expected_demand'],
            'forecast' => $plan['forecast'], 'realized' => $realized,
            'occupancy_bps' => (int) round(10000 * $realized / $capacity),
            'importance' => $plan['importance'], 'ticket_price_cents' => $plan['input']['price_cents'],
            'algorithm_version' => $plan['algorithm_version'], 'snapshot' => $plan,
        ]);
    }

    private function recentForm(Career $career, CareerClub $home, CareerMatch $match): int
    {
        $previous = DB::table('official_match_results as results')
            ->join('matches', fn ($join) => $join->on('matches.id', '=', 'results.match_id')->on('matches.career_id', '=', 'results.career_id'))
            ->where('results.career_id', $career->id)
            ->where('matches.competition_edition_id', $match->competition_edition_id)
            ->where(fn ($query) => $query->where('matches.home_club_id', $home->id)->orWhere('matches.away_club_id', $home->id))
            ->where('matches.scheduled_on', '<', $match->scheduled_on->toDateString())
            ->orderByDesc('matches.scheduled_on')->orderByDesc('matches.id')->limit(5)
            ->get(['matches.home_club_id', 'results.home_goals', 'results.away_goals']);
        $form = 0;
        foreach ($previous as $row) {
            $ours = (int) $row->home_club_id === $home->id ? $row->home_goals : $row->away_goals;
            $theirs = (int) $row->home_club_id === $home->id ? $row->away_goals : $row->home_goals;
            $form += $ours <=> $theirs;
        }

        return $form;
    }
}
