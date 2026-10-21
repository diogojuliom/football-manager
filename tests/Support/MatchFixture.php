<?php

namespace Tests\Support;

use App\Domain\Tactics\Tactics;

class MatchFixture
{
    public static function make(string $seed = 'sample', int $homeQuality = 65, int $awayQuality = 65): array
    {
        $input = ['seed' => $seed, 'home_context' => ['neutral' => true]];
        foreach (['home' => $homeQuality, 'away' => $awayQuality] as $side => $quality) {
            $players = [];
            foreach ([...Tactics::FORMATIONS['4-4-2'], 'GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'ST'] as $index => $position) {
                $players[] = ['id' => $side.'-'.$index, 'name' => $side.' Player '.$index, 'primary_position' => $position, 'secondary_positions' => [], 'fitness' => 100, 'morale' => 70]
                    + array_fill_keys(array_keys(Tactics::ATTRIBUTES), $quality);
            }
            $input[$side] = ['name' => ucfirst($side), 'tactics' => ['formation' => '4-4-2', 'mentality' => 'balanced', 'pressure' => 'normal', 'tempo' => 'normal', 'build_up' => 'balanced'],
                'starters' => array_slice($players, 0, 11), 'substitutes' => array_slice($players, 11)];
        }

        return $input;
    }
}
