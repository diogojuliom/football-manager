<?php

namespace App\Domain\Football\Engine;

final class FootballMatchEngine
{
    public const VERSION = V6\Simulation::VERSION;

    public function simulate(array $input): array
    {
        $validated = new MatchInput($input);

        return match ($validated->data['engine_version']) {
            V1\Simulation::VERSION => (new V1\Simulation($validated->data))->run(),
            V2\Simulation::VERSION => (new V2\Simulation($validated->data))->run(),
            V3\Simulation::VERSION => (new V3\Simulation($validated->data))->run(),
            V4\Simulation::VERSION => (new V4\Simulation($validated->data))->run(),
            V5\Simulation::VERSION => (new V5\Simulation($validated->data))->run(),
            V6\Simulation::VERSION => (new V6\Simulation($validated->data))->run(),
        };
    }
}
