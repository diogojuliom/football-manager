<?php

namespace App\Domain\AI;

use InvalidArgumentException;

final class AdministrationPlanner
{
    public function plan(array $state, array $offers = []): array
    {
        foreach (['balance_cents', 'ticket_price_cents', 'capacity', 'fixed_cost_cents', 'variable_cost_cents', 'potential_supporters', 'loyalty', 'engagement', 'infrastructure_level'] as $field) {
            if (! is_int($state[$field] ?? null) || ($field !== 'balance_cents' && $state[$field] < 0) || abs($state[$field]) > 1000000000000) {
                throw new InvalidArgumentException('Estado administrativo inválido.');
            }
        }
        if ($state['capacity'] < 1 || $state['capacity'] > 10000000 || $state['ticket_price_cents'] > 10000000 || $state['variable_cost_cents'] > 10000000
            || $state['loyalty'] > 100 || $state['engagement'] > 100 || $state['infrastructure_level'] < 1 || $state['infrastructure_level'] > 5) {
            throw new InvalidArgumentException('Parâmetros administrativos fora dos limites.');
        }
        $base = $state['ticket_price_cents'];
        $best = null;
        foreach ([80, 90, 100, 110, 120] as $percent) {
            $price = min(10000000, (int) round($base * $percent / 100));
            // Preliminary planning estimate only; official attendance belongs to the attendance module.
            $demand = $state['potential_supporters'] * (0.5 + $state['loyalty'] / 200) * (0.5 + $state['engagement'] / 200);
            $demand *= $base === 0 ? 1 : pow($base / max(1, $price), 1.3);
            $attendance = min($state['capacity'], max(0, (int) floor($demand)));
            $revenue = $attendance * $price;
            $cost = $state['fixed_cost_cents'] + $attendance * $state['variable_cost_cents'];
            $candidate = ['ticket_price_cents' => $price, 'estimated_attendance' => $attendance,
                'estimated_revenue_cents' => $revenue, 'estimated_operating_cost_cents' => $cost, 'estimated_net_cents' => $revenue - $cost];
            if ($best === null || $candidate['estimated_net_cents'] > $best['estimated_net_cents']
                || ($candidate['estimated_net_cents'] === $best['estimated_net_cents'] && abs($price - $base) < abs($best['ticket_price_cents'] - $base))) {
                $best = $candidate;
            }
        }
        $reserve = 2 * ($state['fixed_cost_cents'] + $state['capacity'] * $state['variable_cost_cents']);
        $budget = max(0, $state['balance_cents'] - $reserve);
        $investment = null;
        foreach ($offers as $offer) {
            if (! is_array($offer) || ! is_string($offer['id'] ?? null) || ! in_array($offer['type'] ?? null, ['capacity', 'modernization'], true)
                || ! is_int($offer['cost_cents'] ?? null) || $offer['cost_cents'] < 1 || ! is_int($offer['days'] ?? null) || $offer['days'] < 1) {
                throw new InvalidArgumentException('Proposta de investimento inválida.');
            }
            $useful = $offer['type'] === 'capacity' ? $best['estimated_attendance'] / $state['capacity'] >= 0.85 : $state['infrastructure_level'] < 5;
            if (! ($state['active_project'] ?? false) && $useful && $best['estimated_net_cents'] > 0 && $offer['cost_cents'] <= $budget
                && ($investment === null || $offer['cost_cents'] < $investment['cost_cents'])) {
                $investment = $offer;
            }
        }

        return ['policy_version' => '1.0.0', 'kind' => 'recommendation', 'operating_reserve_cents' => $reserve,
            'investment_budget_cents' => $budget, 'investment' => $investment] + $best;
    }
}
