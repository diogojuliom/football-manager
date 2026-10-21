<?php

namespace App\Domain\Football\Engine;

final class FootballMatchEngine
{
    public const VERSION = V1\Simulation::VERSION;

    public function simulate(array $input): array
    {
        $validated = new MatchInput($input);

        return (new V1\Simulation($validated->data))->run();
    }
}
