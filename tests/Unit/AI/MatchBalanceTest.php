<?php

namespace Tests\Unit\AI;

use App\Domain\Football\Engine\FootballMatchEngine;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class MatchBalanceTest extends TestCase
{
    public function test_current_ai_has_varied_results_and_responds_to_quality_and_exhaustion(): void
    {
        $engine = new FootballMatchEngine;
        $summary = [];
        foreach (['balanced' => 300, 'strong_home' => 100, 'strong_away' => 100, 'exhausted_home' => 100] as $scenario => $count) {
            $stats = ['goals' => 0, 'home_wins' => 0, 'away_wins' => 0, 'draws' => 0, 'abandoned' => 0];
            $scores = [];
            for ($seed = 0; $seed < $count; $seed++) {
                $input = MatchFixture::make('audit-balance-'.$seed,
                    $scenario === 'strong_home' ? 90 : ($scenario === 'strong_away' ? 35 : 65),
                    $scenario === 'strong_away' ? 90 : ($scenario === 'strong_home' ? 35 : 65));
                $input['engine_version'] = '2.0.0';
                $input['home']['controller'] = $input['away']['controller'] = 'ai';
                if ($scenario === 'exhausted_home') {
                    foreach ($input['home']['starters'] as &$player) {
                        $player['fitness'] = 1;
                        $player['morale'] = 0;
                    }
                    unset($player);
                }
                $result = $engine->simulate($input);
                $home = $result['home']['stats']['goals'];
                $away = $result['away']['stats']['goals'];
                $stats['goals'] += $home + $away;
                $stats[$home > $away ? 'home_wins' : ($away > $home ? 'away_wins' : 'draws')]++;
                $scores[$home.':'.$away] = true;
                if ($result['status'] === 'abandoned') {
                    $stats['abandoned']++;
                    $this->assertGreaterThanOrEqual(5, max($result['home']['stats']['red_cards'], $result['away']['stats']['red_cards']));
                } else {
                    $this->assertSame('finished', $result['status']);
                    $this->assertSame(90, $result['minutes']);
                }
            }
            $this->assertLessThan($count * 0.05, $stats['abandoned']);
            $summary[$scenario] = $stats + ['scorelines' => count($scores)];
        }
        // Broad regression limits, not a claim of calibration against a real-world league.
        $balanced = $summary['balanced'];
        $this->assertGreaterThan(300, $balanced['goals']);
        $this->assertLessThan(1500, $balanced['goals']);
        $this->assertGreaterThan(30, $balanced['draws']);
        $this->assertLessThan(135, $balanced['draws']);
        $this->assertLessThan(60, abs($balanced['home_wins'] - $balanced['away_wins']));
        $this->assertGreaterThan(15, $balanced['scorelines']);
        $this->assertGreaterThan(65, $summary['strong_home']['home_wins']);
        $this->assertGreaterThan(65, $summary['strong_away']['away_wins']);
        $this->assertGreaterThan(55, $summary['exhausted_home']['away_wins']);
    }
}
