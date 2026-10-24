<?php

namespace App\Domain\Football\Engine;

final class SeededRandom
{
    public function __construct(private string $seed, private int $counter = 0) {}

    public function counter(): int
    {
        return $this->counter;
    }

    public function integer(int $min, int $max): int
    {
        $number = hexdec(substr(hash('sha256', $this->seed.':'.$this->counter++), 0, 8));

        return $min + (int) floor(($number / 4294967296) * ($max - $min + 1));
    }

    public function chance(float $probability): bool
    {
        return $this->integer(0, 999999) < (int) round(max(0, min(1, $probability)) * 1000000);
    }

    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $pick = $this->integer(1, max(1, (int) $total));
        foreach ($weights as $key => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
