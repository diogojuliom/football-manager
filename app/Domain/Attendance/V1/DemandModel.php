<?php

namespace App\Domain\Attendance\V1;

use App\Domain\Football\Engine\SeededRandom;
use InvalidArgumentException;

final class DemandModel
{
    public const VERSION = '1.0.0';

    public function forecast(array $input, array $rules): array
    {
        if (($rules['version'] ?? null) !== self::VERSION || ($input['capacity'] ?? 0) < 1
            || ($input['potential_supporters'] ?? -1) < 0 || ($input['price_cents'] ?? -1) < 0) {
            throw new InvalidArgumentException('Dados de público ou versão da demanda inválidos.');
        }

        $late = $input['round'] / max(1, $input['round_count']);
        $remaining = max(0, $input['round_count'] - $input['round']);
        $homeAlive = 3 * ($remaining + 1) >= $input['leader_points'] - $input['home_points'];
        $awayAlive = 3 * ($remaining + 1) >= $input['leader_points'] - $input['away_points'];
        $titleWeight = max(0, ($late - 0.25) / 0.75);
        $importance = min(100, max(0, (int) round(27 + 8 * $late
            + ($input['rivalry'] ? 18 : 0)
            + 9 * min(1.5, $input['opponent_supporters'] / max(1, $input['potential_supporters']))
            + ($homeAlive ? 13 * $titleWeight : 0)
            + ($homeAlive && $awayAlive && $input['home_rank'] <= 3 && $input['away_rank'] <= 3 ? 14 * $titleWeight : 0))));

        // Potential supporters are independent of physical capacity. Loyalty protects a core audience.
        $base = $input['potential_supporters'] * (0.08 + 0.20 * $input['loyalty'] / 100 + 0.14 * $input['engagement'] / 100);
        $form = 1 + 0.18 * $input['recent_form'] / 5;
        $relevance = 0.78 + 0.004 * $importance;
        $opponent = min(1.12, max(0.90, 0.94 + 0.10 * $input['opponent_supporters'] / max(1, $input['potential_supporters'])));
        $rivalry = $input['rivalry'] ? 1.15 : 1.0;
        $infrastructure = 0.88 + 0.04 * $input['infrastructure_level'];
        $price = min($rules['maximum_price_factor'], max($rules['minimum_price_factor'],
            pow($rules['reference_price_cents'] / max(1, $input['price_cents']), $rules['price_elasticity'])));
        $demand = max(0, (int) round($base * $form * $relevance * $opponent * $rivalry * $infrastructure * $price));

        return ['algorithm_version' => self::VERSION, 'importance' => $importance,
            'expected_demand' => $demand, 'forecast' => min($input['capacity'], $demand),
            'input' => $input, 'rules' => $rules];
    }

    public function realize(array $forecast, string $seed): int
    {
        if ($forecast['algorithm_version'] !== self::VERSION) {
            throw new InvalidArgumentException('Versão da previsão não suportada.');
        }
        $variation = (int) $forecast['rules']['realized_variation_percent'];
        $change = (new SeededRandom('attendance-v1:'.$seed))->integer(-$variation, $variation);

        return min($forecast['input']['capacity'], max(0, (int) round($forecast['expected_demand'] * (100 + $change) / 100)));
    }
}
