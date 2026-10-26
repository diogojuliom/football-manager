<?php

namespace App\Domain\Football\Engine\V6;

use InvalidArgumentException;

final class AddedTimeRules
{
    public const VERSION = '1.0.0';

    public const DEFAULTS = [
        'version' => self::VERSION,
        'hydration_seconds' => 180,
        'maximum_added_minutes' => 30,
        'goal_celebration_seconds' => [25, 50],
        'substitution_seconds' => [15, 35],
        'injury_seconds' => [40, 120],
        'card_seconds' => [10, 25],
        'var_seconds' => [45, 150],
        'restart_delay_seconds' => [10, 30],
    ];

    public static function normalize(mixed $rules): array
    {
        if (! is_array($rules) || ($rules['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Versão das regras de acréscimos inválida.');
        }
        if (! is_int($rules['hydration_seconds'] ?? null) || $rules['hydration_seconds'] < 0 || $rules['hydration_seconds'] > 600) {
            throw new InvalidArgumentException('Duração da parada de hidratação inválida.');
        }
        if (! is_int($rules['maximum_added_minutes'] ?? null) || $rules['maximum_added_minutes'] < 0 || $rules['maximum_added_minutes'] > 60) {
            throw new InvalidArgumentException('Limite de acréscimos inválido.');
        }
        foreach (['goal_celebration_seconds', 'substitution_seconds', 'injury_seconds', 'card_seconds', 'var_seconds', 'restart_delay_seconds'] as $key) {
            $range = $rules[$key] ?? null;
            if (! is_array($range) || ! array_is_list($range) || count($range) !== 2
                || ! is_int($range[0]) || ! is_int($range[1]) || $range[0] < 0 || $range[1] < $range[0] || $range[1] > 600) {
                throw new InvalidArgumentException('Intervalo de duração dos acréscimos inválido.');
            }
        }

        return array_replace(self::DEFAULTS, array_intersect_key($rules, self::DEFAULTS));
    }
}
