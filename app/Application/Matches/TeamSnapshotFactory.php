<?php

namespace App\Application\Matches;

use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Tactics\Tactics;
use Illuminate\Validation\ValidationException;

class TeamSnapshotFactory
{
    public function __construct(private SquadSheet $sheets) {}

    public function make(Career $career, CareerClub $club, bool $requireSaved): array
    {
        $sheet = $this->sheets->forClub($career, $club);
        if ($requireSaved && ! $sheet['lineup']) {
            throw ValidationException::withMessages(['lineup' => 'Salve a escalação e as táticas do seu clube antes de iniciar o amistoso.']);
        }
        $settings = $sheet['settings'];
        $players = $sheet['players']->keyBy('id');
        $team = ['name' => $club->name, 'tactics' => array_intersect_key($settings, array_flip(['formation', ...array_keys(Tactics::OPTIONS)]))];
        if (count($settings['substitutes']) > $sheet['limit']) {
            throw ValidationException::withMessages(['lineup' => 'A escalação salva excede o limite atual de reservas.']);
        }
        foreach (['starters', 'substitutes'] as $unit) {
            $team[$unit] = [];
            foreach ($settings[$unit] as $id) {
                $player = $players[$id] ?? null;
                if (! $player || ! $player->isAvailableOn($career->current_date)) {
                    throw ValidationException::withMessages(['lineup' => 'Há atletas indisponíveis na escalação. Revise os titulares e reservas.']);
                }
                $team[$unit][] = ['id' => (string) $player->id, 'name' => $player->name,
                    'primary_position' => $player->primary_position, 'secondary_positions' => $player->secondary_positions,
                    'fitness' => (int) $player->fitness, 'morale' => (int) $player->morale, 'available' => true]
                    + array_map('intval', $player->only(array_keys(Tactics::ATTRIBUTES)));
            }
        }

        return $team;
    }
}
