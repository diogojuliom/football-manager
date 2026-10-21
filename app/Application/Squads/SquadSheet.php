<?php

namespace App\Application\Squads;

use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Tactics\Tactics;

class SquadSheet
{
    public function maxSubstitutes(Career $career): int
    {
        $season = $career->seasons()->where('number', $career->current_season_number)->firstOrFail();
        $edition = CompetitionEdition::where('career_id', $career->id)->where('career_season_id', $season->id)->sole();

        return (int) ($edition->rules['max_substitutes'] ?? 7);
    }

    public function editable(Career $career): bool
    {
        return in_array($career->state, [CareerState::AwaitingDecision, CareerState::PreMatch, CareerState::AwaitingSeason], true);
    }

    public function forCareer(Career $career, ?string $formation = null): array
    {
        return $this->forClub($career, $career->controlledClub->club, $formation);
    }

    public function forClub(Career $career, CareerClub $club, ?string $formation = null): array
    {
        $players = $club->players()->orderBy('id')->get();
        $lineup = $club->lineup()->with('selections')->first();
        $formation = array_key_exists($formation ?? '', Tactics::FORMATIONS) ? $formation : ($lineup?->formation ?? '4-4-2');
        $limit = $this->maxSubstitutes($career);
        $starters = [];
        $substitutes = [];
        if ($lineup && $formation === $lineup->formation) {
            $starters = $lineup->selections->where('unit', 'starter')->pluck('career_player_id')->all();
            $substitutes = $lineup->selections->where('unit', 'substitute')->pluck('career_player_id')->all();
        } else {
            // An unsaved suggestion only; opening this screen never changes game state.
            $available = $players->filter(fn ($p) => $p->isAvailableOn($career->current_date));
            foreach (Tactics::FORMATIONS[$formation] as $position) {
                $candidates = $available->reject(fn ($p) => in_array($p->id, $starters, true))
                    ->filter(fn ($p) => $position === 'GK' ? $p->canPlay('GK') : ! $p->canPlay('GK'));
                $player = $candidates->first(fn ($p) => $p->primary_position === $position)
                    ?? $candidates->first(fn ($p) => $p->canPlay($position))
                    ?? $candidates->first();
                $starters[] = $player?->id;
            }
            $substitutes = $available->reject(fn ($p) => in_array($p->id, $starters, true))->take($limit)->pluck('id')->all();
        }
        $settings = [
            'formation' => $formation, 'mentality' => $lineup?->mentality ?? 'balanced',
            'pressure' => $lineup?->pressure ?? 'normal', 'tempo' => $lineup?->tempo ?? 'normal',
            'build_up' => $lineup?->build_up ?? 'balanced', 'revision' => $lineup?->revision ?? 0,
            'starters' => $starters, 'substitutes' => $substitutes,
        ];

        return compact('club', 'players', 'lineup', 'settings', 'limit');
    }
}
