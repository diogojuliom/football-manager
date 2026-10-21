<?php

namespace App\Application\AI;

use App\Application\Squads\SquadSheet;
use App\Domain\AI\AdministrationPlanner;
use App\Domain\AI\LineupPlanner;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CareerClubController;
use App\Domain\Tactics\Tactics;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ClubSquadSnapshot
{
    public function __construct(private LineupPlanner $lineups, private AdministrationPlanner $administration, private SquadSheet $rules) {}

    public function make(Career $career, CareerClub $club, CareerClub $opponent, bool $home): array
    {
        if ($club->career_id !== $career->id || $opponent->career_id !== $career->id
            || ! CareerClubController::where('career_id', $career->id)->where('career_club_id', $club->id)->where('type', 'ai')->exists()) {
            throw ValidationException::withMessages(['club' => 'A IA só pode administrar seus clubes nesta carreira.']);
        }
        try {
            $team = $this->lineups->plan($this->roster($career, $club), $this->roster($career, $opponent), $this->rules->maxSubstitutes($career), $home);
            $stadium = $club->stadium;
            $support = $club->support;
            if (! $stadium || ! $support) {
                throw new InvalidArgumentException('Dados administrativos incompletos.');
            }
            $plan = $this->administration->plan([
                'balance_cents' => (int) $club->balance_cents, 'ticket_price_cents' => (int) $club->default_ticket_price_cents,
                'capacity' => (int) $stadium->available_capacity, 'infrastructure_level' => (int) $stadium->infrastructure_level,
                'fixed_cost_cents' => (int) $stadium->fixed_operating_cost_cents,
                'variable_cost_cents' => (int) $stadium->variable_cost_per_supporter_cents,
                'potential_supporters' => (int) $support->potential_supporters, 'loyalty' => (int) $support->loyalty, 'engagement' => (int) $support->engagement,
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lineup' => $exception->getMessage()]);
        }

        return ['name' => $club->name, 'administration_plan' => $plan] + $team;
    }

    private function roster(Career $career, CareerClub $club): array
    {
        return $club->players()->where('career_id', $career->id)->orderBy('id')->get()->map(fn ($player) => [
            'id' => (string) $player->id, 'name' => $player->name, 'primary_position' => $player->primary_position,
            'secondary_positions' => $player->secondary_positions, 'fitness' => (int) $player->fitness,
            'morale' => (int) $player->morale, 'available' => $player->isAvailableOn($career->current_date),
        ] + array_map('intval', $player->only(array_keys(Tactics::ATTRIBUTES))))->all();
    }
}
