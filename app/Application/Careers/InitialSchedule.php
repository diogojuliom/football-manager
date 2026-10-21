<?php

namespace App\Application\Careers;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class InitialSchedule
{
    public function generate(array $clubs, CarbonImmutable $firstMatch): array
    {
        $count = count($clubs);
        if ($count < 2 || $count % 2 !== 0 || count(array_unique($clubs)) !== $count) {
            throw new InvalidArgumentException('A liga exige uma quantidade par de clubes distintos.');
        }

        $matches = [];
        for ($round = 0; $round < $count - 1; $round++) {
            for ($pair = 0; $pair < $count / 2; $pair++) {
                [$home, $away] = [$clubs[$pair], $clubs[$count - 1 - $pair]];
                if (($round + $pair) % 2 !== 0) {
                    [$home, $away] = [$away, $home];
                }
                foreach ([0, 1] as $leg) {
                    $offset = $round + $leg * ($count - 1);
                    $matches[] = [
                        'home_club_id' => $leg === 0 ? $home : $away,
                        'away_club_id' => $leg === 0 ? $away : $home,
                        'round' => $offset + 1,
                        'scheduled_on' => $firstMatch->addDays($offset * config('careers.days_between_rounds'))->toDateString(),
                        'status' => 'scheduled',
                    ];
                }
            }
            $last = array_pop($clubs);
            array_splice($clubs, 1, 0, [$last]);
        }

        return $matches;
    }
}
