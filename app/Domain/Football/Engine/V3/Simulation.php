<?php

namespace App\Domain\Football\Engine\V3;

/**
 * Formation-aware engine. V2 remains immutable so historical match snapshots
 * can still be replayed byte for byte.
 */
final class Simulation
{
    public const VERSION = '3.0.0';

    public function __construct(private array $input) {}

    public function run(): array
    {
        return (new \App\Domain\Football\Engine\V2\Simulation($this->input, self::VERSION, true))->run();
    }
}
