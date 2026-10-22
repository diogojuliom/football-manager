<?php

namespace App\Application\Competitions;

use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CompetitionEdition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StandingsService
{
    public function standings(CompetitionEdition $edition, ?CarbonImmutable $beforeDate = null): array
    {
        $ids = DB::table('competition_entries')->where('career_id', $edition->career_id)->where('competition_edition_id', $edition->id)->pluck('career_club_id');
        $rows = [];
        foreach (CareerClub::where('career_id', $edition->career_id)->whereIn('id', $ids)->get() as $club) {
            $rows[$club->id] = ['club_id' => $club->id, 'club_code' => $club->code, 'name' => $club->name,
                'played' => 0, 'points' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0];
        }
        foreach ($this->results($edition, $beforeDate)->get(['matches.home_club_id', 'matches.away_club_id', 'results.home_goals', 'results.away_goals']) as $result) {
            foreach (['home', 'away'] as $side) {
                $other = $side === 'home' ? 'away' : 'home';
                $row = &$rows[$result->{$side.'_club_id'}];
                $goals = (int) $result->{$side.'_goals'};
                $against = (int) $result->{$other.'_goals'};
                $outcome = $goals > $against ? 'win' : ($goals === $against ? 'draw' : 'loss');
                $row['played']++;
                $row['points'] += $edition->rules['points_for_'.$outcome];
                $row[match ($outcome) {
                    'win' => 'wins', 'draw' => 'draws', 'loss' => 'losses'
                }]++;
                $row['goals_for'] += $goals;
                $row['goals_against'] += $against;
                $row['goal_difference'] = $row['goals_for'] - $row['goals_against'];
                unset($row);
            }
        }
        $criteria = $edition->rules['tiebreakers'];
        foreach ($criteria as $criterion) {
            if (! in_array($criterion, ['wins', 'goal_difference', 'goals_for', 'goals_against', 'club_code'], true)) {
                throw new InvalidArgumentException('Critério de desempate não suportado.');
            }
        }
        usort($rows, function ($a, $b) use ($criteria) {
            foreach (['points', ...$criteria, 'club_code'] as $field) {
                $comparison = $field === 'club_code' ? strcmp($a[$field], $b[$field])
                    : ($field === 'goals_against' ? $a[$field] <=> $b[$field] : $b[$field] <=> $a[$field]);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $a['club_id'] <=> $b['club_id'];
        });

        return $rows;
    }

    public function scorers(CompetitionEdition $edition): array
    {
        $rows = [];
        $results = $this->results($edition)->get([
            'matches.home_club_id', 'matches.away_club_id',
            'results.result->home->players as home_players', 'results.result->away->players as away_players',
            'results.result->home->name as home_name', 'results.result->away->name as away_name',
        ]);
        foreach ($results as $result) {
            foreach (['home', 'away'] as $side) {
                foreach (json_decode($result->{$side.'_players'}, true, 512, JSON_THROW_ON_ERROR) as $stats) {
                    if ($stats['goals'] === 0) {
                        continue;
                    }
                    $id = $stats['player_id'];
                    $rows[$id] ??= ['player_id' => (int) $id, 'name' => $stats['name'], 'club_id' => (int) $result->{$side.'_club_id'},
                        'club_name' => $result->{$side.'_name'}, 'goals' => 0];
                    $rows[$id]['goals'] += $stats['goals'];
                }
            }
        }
        usort($rows, fn ($a, $b) => $b['goals'] <=> $a['goals'] ?: $a['player_id'] <=> $b['player_id']);

        return $rows;
    }

    private function results(CompetitionEdition $edition, ?CarbonImmutable $beforeDate = null): Builder
    {
        $query = DB::table('official_match_results as results')
            ->join('matches', function ($join) {
                $join->on('matches.id', '=', 'results.match_id')->on('matches.career_id', '=', 'results.career_id');
            })
            ->where('results.career_id', $edition->career_id)->where('matches.competition_edition_id', $edition->id);
        if ($beforeDate) {
            $query->where('matches.scheduled_on', '<', $beforeDate->toDateString());
        }

        return $query->orderBy('results.match_id');
    }
}
