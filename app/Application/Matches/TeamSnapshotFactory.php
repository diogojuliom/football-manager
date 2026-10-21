<?php

namespace App\Application\Matches;

use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Tactics\Tactics;
use Illuminate\Validation\ValidationException;

class TeamSnapshotFactory
{
    public function __construct(private SquadSheet $sheets, private \App\Application\AI\ClubSquadSnapshot $ai) {}

    public function make(Career $career, CareerClub $club, bool $requireSaved, ?CareerClub $opponent = null, bool $home = false): array
    {
        if (! $requireSaved) {
            if ($opponent === null) {
                throw ValidationException::withMessages(['lineup' => 'Informe o adversário para preparar a IA.']);
            }

            return $this->ai->make($career, $club, $opponent, $home);
        }
        $sheet = $this->sheets->forClub($career, $club);
        if ($requireSaved && ! $sheet['lineup']) {
            throw ValidationException::withMessages(['lineup' => 'Salve a escalação e as táticas do seu clube antes de iniciar o amistoso.']);
        }
        $settings = $sheet['settings'];
        $players = $sheet['players']->keyBy('id');
        $team = ['name' => $club->name, 'controller' => 'human', 'tactics' => array_intersect_key($settings, array_flip(['formation', ...array_keys(Tactics::OPTIONS)]))];
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
