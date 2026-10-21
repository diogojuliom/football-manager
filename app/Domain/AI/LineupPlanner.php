<?php

namespace App\Domain\AI;

use App\Domain\AI\V1\MatchDecisions;
use App\Domain\AI\V1\PlayerScore;
use App\Domain\Tactics\Tactics;
use InvalidArgumentException;

final class LineupPlanner
{
    public function plan(array $roster, array $opponent, int $maxReserves = 7, bool $home = false): array
    {
        if ($maxReserves < 0 || $maxReserves > 12) {
            throw new InvalidArgumentException('Limite de reservas inválido.');
        }
        $available = $this->available($roster);
        if (count(array_filter($available, fn ($p) => PlayerScore::keeper($p))) < 1
            || count(array_filter($available, fn ($p) => ! PlayerScore::keeper($p))) < 10) {
            throw new InvalidArgumentException('A IA não possui goleiro e dez jogadores de linha disponíveis.');
        }
        $best = null;
        foreach (Tactics::FORMATIONS as $formation => $roles) {
            $assignment = $this->assign($roles, $available);
            $score = 0;
            $starters = [];
            foreach ($assignment as $slot => $column) {
                $starters[] = $available[$column];
                $score += PlayerScore::forRole($available[$column], $roles[$slot]);
            }
            if ($best === null || $score > $best['score']) {
                $best = compact('formation', 'score', 'starters');
            }
        }
        $remaining = array_values(array_filter($available, fn ($p) => ! in_array($p['id'], array_column($best['starters'], 'id'), true)));
        $bench = [];
        // A reserve goalkeeper comes first, then complementary outfield cover by role.
        foreach (['GK', 'CB', 'DM', 'CM', 'ST', 'LW', 'RW', 'LB', 'RB', 'AM', 'CB', 'CM'] as $role) {
            if (count($bench) >= $maxReserves || $remaining === []) {
                break;
            }
            $candidates = array_filter($remaining, fn ($p) => ($role === 'GK') === PlayerScore::keeper($p));
            if ($candidates === []) {
                continue;
            }
            uasort($candidates, fn ($a, $b) => PlayerScore::forRole($b, $role) <=> PlayerScore::forRole($a, $role) ?: strcmp($a['id'], $b['id']));
            $chosen = reset($candidates);
            $bench[] = $chosen;
            $remaining = array_values(array_filter($remaining, fn ($p) => $p['id'] !== $chosen['id']));
        }
        $strength = $best['score'] / 11000;
        $opposition = $this->estimateStrength($this->available($opponent));
        $fitness = array_sum(array_column($best['starters'], 'fitness')) / 11;

        return ['controller' => 'ai', 'ai_policy_version' => MatchDecisions::VERSION,
            'tactics' => ['formation' => $best['formation']] + MatchDecisions::initial($strength, $opposition, $fitness, $home),
            'starters' => $best['starters'], 'substitutes' => $bench];
    }

    private function available(array $roster): array
    {
        if (! array_is_list($roster) || count($roster) > 100) {
            throw new InvalidArgumentException('Elenco inválido.');
        }
        $ids = [];
        foreach ($roster as $player) {
            if (! is_array($player) || ! is_string($player['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $player['id']) || isset($ids[$player['id']])
                || ! is_string($player['name'] ?? null) || ! in_array($player['primary_position'] ?? null, ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'], true)
                || ! is_array($player['secondary_positions'] ?? null) || ! is_bool($player['available'] ?? true)) {
                throw new InvalidArgumentException('Atleta inválido no elenco da IA.');
            }
            foreach ($player['secondary_positions'] as $position) {
                if (! in_array($position, ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'], true)) {
                    throw new InvalidArgumentException('Posição secundária inválida.');
                }
            }
            foreach ([...array_keys(Tactics::ATTRIBUTES), 'fitness', 'morale'] as $field) {
                if (! is_int($player[$field] ?? null) || $player[$field] < 0 || $player[$field] > 100) {
                    throw new InvalidArgumentException('Atributos inválidos no elenco da IA.');
                }
            }
            $ids[$player['id']] = true;
        }
        $roster = array_values(array_filter($roster, fn ($p) => ($p['available'] ?? true) && $p['fitness'] > 0));
        usort($roster, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $roster;
    }

    private function estimateStrength(array $roster): float
    {
        $ratings = [];
        foreach ($roster as $player) {
            $ratings[] = PlayerScore::forRole($player, $player['primary_position']) / 1000;
        }
        rsort($ratings);
        $ratings = array_slice($ratings, 0, 11);

        return $ratings === [] ? 1 : max(1, array_sum($ratings) / count($ratings));
    }

    /** Hungarian assignment: eleven positions, distinct players, maximum total role score. */
    private function assign(array $roles, array $players): array
    {
        $n = count($roles);
        $m = count($players);
        $u = array_fill(0, $n + 1, 0);
        $v = $p = $way = array_fill(0, $m + 1, 0);
        for ($i = 1; $i <= $n; $i++) {
            $p[0] = $i;
            $j0 = 0;
            $min = array_fill(0, $m + 1, 1000000000);
            $used = array_fill(0, $m + 1, false);
            do {
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = 1000000000;
                $j1 = 0;
                for ($j = 1; $j <= $m; $j++) {
                    if ($used[$j]) {
                        continue;
                    }
                    $cost = -PlayerScore::forRole($players[$j - 1], $roles[$i0 - 1]) - $u[$i0] - $v[$j];
                    if ($cost < $min[$j]) {
                        $min[$j] = $cost;
                        $way[$j] = $j0;
                    }
                    if ($min[$j] < $delta) {
                        $delta = $min[$j];
                        $j1 = $j;
                    }
                }
                for ($j = 0; $j <= $m; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } else {
                        $min[$j] -= $delta;
                    }
                }
                $j0 = $j1;
            } while ($p[$j0] !== 0);
            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }
        $assignment = [];
        for ($j = 1; $j <= $m; $j++) {
            if ($p[$j] > 0) {
                $assignment[$p[$j] - 1] = $j - 1;
            }
        }
        ksort($assignment);

        return $assignment;
    }
}
