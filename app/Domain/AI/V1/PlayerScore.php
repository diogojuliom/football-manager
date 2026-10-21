<?php

namespace App\Domain\AI\V1;

/** Published scoring used by engine V2 substitutions; preserve for stored replays. */
final class PlayerScore
{
    private const WEIGHTS = [
        'GK' => ['goalkeeping' => 60, 'composure' => 15, 'passing' => 10, 'discipline' => 5, 'stamina' => 10],
        'CB' => ['defense' => 30, 'tackling' => 30, 'composure' => 15, 'discipline' => 10, 'stamina' => 15],
        'LB' => ['defense' => 20, 'tackling' => 20, 'speed' => 20, 'stamina' => 20, 'passing' => 20],
        'RB' => ['defense' => 20, 'tackling' => 20, 'speed' => 20, 'stamina' => 20, 'passing' => 20],
        'DM' => ['defense' => 20, 'tackling' => 20, 'passing' => 25, 'stamina' => 20, 'discipline' => 15],
        'CM' => ['passing' => 30, 'creativity' => 25, 'stamina' => 20, 'composure' => 15, 'tackling' => 10],
        'AM' => ['creativity' => 30, 'passing' => 25, 'finishing' => 25, 'composure' => 10, 'set_pieces' => 10],
        'LW' => ['speed' => 25, 'finishing' => 25, 'creativity' => 20, 'passing' => 20, 'stamina' => 10],
        'RW' => ['speed' => 25, 'finishing' => 25, 'creativity' => 20, 'passing' => 20, 'stamina' => 10],
        'ST' => ['finishing' => 45, 'speed' => 20, 'composure' => 20, 'set_pieces' => 15],
    ];

    public static function keeper(array $player): bool
    {
        return $player['primary_position'] === 'GK' || in_array('GK', $player['secondary_positions'], true);
    }

    public static function forRole(array $player, string $role): int
    {
        if (($role === 'GK') !== self::keeper($player)) {
            return -1000000;
        }
        $skill = 0;
        foreach (self::WEIGHTS[$role] as $attribute => $weight) {
            $skill += $player[$attribute] * $weight / 100;
        }
        $fit = $player['primary_position'] === $role ? 1 : (in_array($role, $player['secondary_positions'], true) ? 0.95 : 0.65);

        return (int) round($skill * $fit * (0.35 + 0.65 * $player['fitness'] / 100) * (0.85 + 0.30 * $player['morale'] / 100) * 1000);
    }
}
