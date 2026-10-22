<?php

namespace App\Application\Attendance;

use App\Domain\Career\Models\CareerClub;

class FanEngagementService
{
    public function apply(CareerClub $home, CareerClub $away, array $result, array $plan): void
    {
        foreach (['home' => $home, 'away' => $away] as $side => $club) {
            $goals = $result[$side]['stats']['goals'];
            $other = $result[$side === 'home' ? 'away' : 'home']['stats']['goals'];
            $form = $plan['input'][$side === 'home' ? 'recent_form' : 'away_recent_form'] ?? 0;
            $rank = $plan['input'][$side.'_rank'];
            $delta = $this->delta($goals, $other, $form, $plan['importance'], $rank);
            $support = $club->support;
            $support->update(['engagement' => max(0, min(100, $support->engagement + $delta))]);
        }
    }

    public function delta(int $goals, int $opponentGoals, int $recentForm, int $importance, int $rank): int
    {
        if ($goals > $opponentGoals) {
            return 2 + (int) ($recentForm >= 3 || ($importance >= 65 && $rank <= 3));
        }
        if ($goals < $opponentGoals) {
            return -2 - (int) ($recentForm <= -3);
        }

        return 0;
    }
}
