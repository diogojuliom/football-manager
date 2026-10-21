<?php

namespace App\Domain\AI\V1;

/** Published rules used by engine V2 replays; behavior changes require a new version. */
final class MatchDecisions
{
    public const VERSION = '1.0.0';

    public static function initial(float $strength, float $opponent, float $fitness, bool $home): array
    {
        $ratio = $strength / max(1, $opponent);
        $mentality = $ratio < 0.85 ? 'defensive' : ($ratio > 1.15 ? 'attacking' : 'balanced');

        return ['mentality' => $mentality,
            'pressure' => $fitness < 65 || $mentality === 'defensive' ? 'low' : ($fitness >= 80 && $mentality === 'attacking' ? 'high' : 'normal'),
            'tempo' => $fitness < 65 ? 'slow' : ($mentality === 'attacking' ? 'fast' : 'normal'),
            'build_up' => $mentality === 'defensive' ? 'direct' : ($home ? 'short' : 'balanced')];
    }

    public static function react(array $tactics, int $minute, int $goalDifference, int $ownPlayers, int $opponentPlayers, float $fitness): array
    {
        $reason = null;
        $next = $tactics;
        if ($minute >= 60 && $goalDifference < 0) {
            $next = array_replace($next, ['mentality' => 'attacking', 'pressure' => $fitness < 55 ? 'low' : 'high', 'tempo' => 'fast', 'build_up' => 'direct']);
            $reason = 'chasing_goal';
        } elseif ($minute >= 70 && $goalDifference > 0) {
            $next = array_replace($next, ['mentality' => 'defensive', 'pressure' => 'low', 'tempo' => 'slow', 'build_up' => 'short']);
            $reason = 'protecting_lead';
        } elseif ($ownPlayers < $opponentPlayers) {
            $next = array_replace($next, ['mentality' => 'defensive', 'pressure' => 'low', 'tempo' => 'normal', 'build_up' => 'direct']);
            $reason = 'numerical_disadvantage';
        } elseif ($ownPlayers > $opponentPlayers && $minute >= 30) {
            $next = array_replace($next, ['mentality' => 'attacking', 'pressure' => 'normal', 'tempo' => 'fast']);
            $reason = 'numerical_advantage';
        }
        if ($fitness < 50) {
            $next['pressure'] = 'low';
            $next['tempo'] = $goalDifference < 0 && $minute >= 75 ? 'normal' : 'slow';
            $reason ??= 'conserve_energy';
        }

        return ['tactics' => $next, 'reason' => $next === $tactics ? null : $reason];
    }

    public static function substitute(int $minute, int $lastChange, int $goalDifference, array $players, array $active, array $bench, array $roles): ?array
    {
        if ($minute - $lastChange < 10 || $bench === []) {
            return null;
        }
        $best = null;
        foreach ($active as $slot => $out) {
            $player = $players[$out];
            $fitness = $player['energy'] / 100;
            $booked = $player['stats']['yellow_cards'] > 0;
            if ($minute < 45 && $fitness >= 35 || $minute < 60 && $fitness >= 75 && ! $booked) {
                continue;
            }
            if ($slot === 0 && $fitness >= 35) {
                continue;
            }
            $role = $roles[$slot];
            $current = $player;
            $current['fitness'] = (int) round($fitness);
            foreach ($bench as $in) {
                $replacement = $players[$in];
                if (($slot === 0) !== PlayerScore::keeper($replacement)) {
                    continue;
                }
                $priority = (100 - $fitness) + ($booked ? 18 : 0);
                $gain = (PlayerScore::forRole($replacement, $role) - PlayerScore::forRole($current, $role)) / 1000;
                $attackRole = in_array($role, ['ST', 'AM', 'LW', 'RW'], true);
                $priority += $minute >= 60 && (($goalDifference < 0 && $attackRole) || ($goalDifference > 0 && ! $attackRole)) ? 12 : 0;
                $score = $priority + $gain;
                if ($best === null || $score > $best['score']) {
                    $reason = $fitness < 65 ? 'fatigue' : ($booked ? 'booking' : ($goalDifference < 0 ? 'chasing_goal' : ($goalDifference > 0 ? 'protecting_lead' : 'refresh')));
                    $best = ['slot' => $slot, 'in' => $in, 'score' => $score, 'reason' => $reason];
                }
            }
        }

        return $best;
    }
}
