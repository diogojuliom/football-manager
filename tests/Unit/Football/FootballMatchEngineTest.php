<?php

namespace Tests\Unit\Football;

use App\Domain\Football\Engine\FootballMatchEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class FootballMatchEngineTest extends TestCase
{
    public function test_same_snapshot_seed_and_version_produce_identical_results_without_mutating_input(): void
    {
        $input = MatchFixture::make();
        $before = $input;
        $engine = new FootballMatchEngine;
        $result = $engine->simulate($input);
        $this->assertSame($result, $engine->simulate($input));
        $this->assertSame('26814de60f08eb15e0b44314f48d11d4431911e79432d338e3127a57080d2900', \App\Domain\Football\Engine\ReplayHash::of($result), 'A released engine version must preserve its reference replay.');
        $this->assertSame($before, $input);
        $this->assertSame('1.0.0', $result['engine_version']);
        $this->assertSame('sample', $result['seed']);
        $this->assertSame(90, $result['minutes']);
        $this->assertSame('finished', $result['status']);
    }

    public static function engineModes(): array
    {
        return ['legacy' => ['1.0.0', 'human'], 'current human' => ['2.0.0', 'human'], 'current AI' => ['2.0.0', 'ai']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('engineModes')]
    public function test_statistics_reconcile_with_events_players_and_score_over_many_seeds(string $version, string $controller): void
    {
        $engine = new FootballMatchEngine;
        $types = [];
        $signatures = [];
        for ($seed = 0; $seed < 100; $seed++) {
            $input = MatchFixture::make('invariants-'.$seed);
            $input['engine_version'] = $version;
            $input['home']['controller'] = $input['away']['controller'] = $controller;
            $result = $engine->simulate($input);
            $this->assertSame($result, $engine->simulate($input));
            $this->assertEqualsWithDelta(100, $result['home']['stats']['possession'] + $result['away']['stats']['possession'], 0.000001);
            $minutes = array_column($result['events'], 'minute');
            $sorted = $minutes;
            sort($sorted);
            $this->assertSame($sorted, $minutes);
            $this->assertSame(range(1, count($result['events'])), array_column($result['events'], 'sequence'));
            $this->assertSame('kickoff', $result['events'][0]['type']);
            $this->assertSame('full_time', end($result['events'])['type']);
            foreach (['home', 'away'] as $side) {
                $stats = $result[$side]['stats'];
                $events = array_filter($result['events'], fn ($e) => $e['side'] === $side);
                $players = $result[$side]['players'];
                $byType = array_count_values(array_column($events, 'type'));
                foreach (['goals' => 'goal', 'assists' => 'assist', 'shots' => 'shot', 'fouls' => 'foul', 'yellow_cards' => 'yellow_card', 'red_cards' => 'red_card', 'saves' => 'save', 'substitutions' => 'substitution', 'opportunities' => 'opportunity'] as $field => $type) {
                    $this->assertSame($byType[$type] ?? 0, $stats[$field]);
                    if (! in_array($field, ['substitutions', 'opportunities'], true)) {
                        $this->assertSame(array_sum(array_column($players, $field)), $stats[$field]);
                    }
                }
                $this->assertSame(array_sum(array_column($players, 'shots_on_target')), $stats['shots_on_target']);
                $this->assertSame(array_sum(array_column($players, 'xg_millis')), $stats['xg_millis']);
                $this->assertLessThanOrEqual($stats['shots'], $stats['shots_on_target']);
                $this->assertLessThanOrEqual($stats['shots_on_target'], $stats['goals']);
                $this->assertLessThanOrEqual(3, $stats['substitutions']);
                $other = $side === 'home' ? 'away' : 'home';
                $this->assertSame($stats['shots_on_target'] - $stats['goals'], $result[$other]['stats']['saves']);
                $active = array_fill_keys(array_column(MatchFixture::make()[$side]['starters'], 'id'), true);
                foreach ($events as $event) {
                    $types[$event['type']] = true;
                    if ($event['type'] === 'substitution') {
                        $this->assertArrayHasKey($event['data']['out_player_id'], $active);
                        $this->assertArrayNotHasKey($event['player_id'], $active);
                        unset($active[$event['data']['out_player_id']]);
                        $active[$event['player_id']] = true;
                    } elseif ($event['player_id'] !== null) {
                        $this->assertArrayHasKey($event['player_id'], $active, 'A substituted/sent-off player cannot act again.');
                        if ($event['type'] === 'red_card') {
                            unset($active[$event['player_id']]);
                        }
                    }
                }
                $lostMinutes = 0;
                foreach ($events as $event) {
                    if ($event['type'] === 'red_card') {
                        $lostMinutes += $result['minutes'] - $event['minute'];
                    }
                }
                $this->assertSame($result['minutes'] * 11 - $lostMinutes, array_sum(array_column($players, 'minutes')));
                foreach ($players as $player) {
                    $this->assertGreaterThanOrEqual(0, $player['final_fitness']);
                    $this->assertLessThanOrEqual($player['initial_fitness'], $player['final_fitness']);
                    $this->assertLessThanOrEqual($result['minutes'], $player['minutes']);
                    $this->assertLessThanOrEqual(2, $player['yellow_cards']);
                    $this->assertLessThanOrEqual(1, $player['red_cards']);
                }
            }
            $signatures[] = hash('sha256', json_encode($result));
        }
        foreach (['possession', 'build_up', 'opportunity', 'shot', 'save', 'goal', 'assist', 'foul', 'yellow_card', 'red_card', 'substitution', 'fatigue'] as $type) {
            $this->assertArrayHasKey($type, $types);
        }
        $this->assertCount(100, array_unique($signatures));
    }

    public function test_pressure_and_tempo_increase_fatigue_and_tactics_change_the_simulation(): void
    {
        $engine = new FootballMatchEngine;
        $slow = MatchFixture::make();
        $slow['rules'] = ['max_substitutions' => 0];
        $slow['home']['tactics']['pressure'] = 'low';
        $slow['home']['tactics']['tempo'] = 'slow';
        $fast = $slow;
        $fast['home']['tactics']['pressure'] = 'high';
        $fast['home']['tactics']['tempo'] = 'fast';
        $first = $engine->simulate($slow);
        $second = $engine->simulate($fast);
        $this->assertLessThan(array_sum(array_column($first['home']['players'], 'final_fitness')), array_sum(array_column($second['home']['players'], 'final_fitness')));
        foreach (['mentality' => 'attacking', 'build_up' => 'direct'] as $field => $value) {
            $changed = $slow;
            $changed['home']['tactics'][$field] = $value;
            $this->assertNotSame($first, $engine->simulate($changed));
        }
    }

    public function test_home_context_is_frozen_and_crowd_bonus_is_bounded(): void
    {
        $engine = new FootballMatchEngine;
        $input = MatchFixture::make();
        $input['home_context'] = ['neutral' => false, 'attendance' => 20000, 'capacity' => 20000, 'engagement' => 100, 'ticket_price_cents' => 3000];
        $result = $engine->simulate($input);
        $this->assertSame(0.05, $result['home_bonus']);
        $this->assertSame(20000, $result['attendance']['present']);
        $this->assertEquals(1, $result['attendance']['occupancy']);
        $this->assertSame(60000000, $result['attendance']['ticket_revenue_cents']);
        $input['home_context']['neutral'] = true;
        $this->assertSame(0.0, $engine->simulate($input)['home_bonus']);
    }

    public function test_substitution_limits_and_no_bench_are_supported(): void
    {
        foreach ([0, 1, 3, 5] as $limit) {
            $input = MatchFixture::make();
            $input['rules'] = ['max_substitutions' => $limit];
            $result = (new FootballMatchEngine)->simulate($input);
            $this->assertSame($limit, $result['home']['stats']['substitutions']);
        }
        $input['home']['substitutes'] = [];
        $this->assertSame(0, (new FootballMatchEngine)->simulate($input)['home']['stats']['substitutions']);
    }

    public function test_formation_morale_fitness_and_extreme_attributes_affect_play_safely(): void
    {
        $engine = new FootballMatchEngine;
        $baseline = MatchFixture::make();
        $original = $engine->simulate($baseline);
        $changed = $baseline;
        $changed['home']['tactics']['formation'] = '4-3-3';
        $this->assertNotSame($original, $engine->simulate($changed));
        foreach (['fitness' => 1, 'morale' => 0] as $attribute => $value) {
            $changed = $baseline;
            foreach ($changed['home']['starters'] as &$player) {
                $player[$attribute] = $value;
            }
            unset($player);
            $this->assertNotSame($original, $engine->simulate($changed));
        }
        foreach ([0, 100] as $rating) {
            $changed = $baseline;
            foreach (['home', 'away'] as $side) {
                foreach (['starters', 'substitutes'] as $unit) {
                    foreach ($changed[$side][$unit] as &$player) {
                        foreach (array_keys(\App\Domain\Tactics\Tactics::ATTRIBUTES) as $attribute) {
                            $player[$attribute] = $rating;
                        }
                    }
                    unset($player);
                }
            }
            $result = $engine->simulate($changed);
            $this->assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertGreaterThanOrEqual(0, $result['home']['stats']['xg']);
        }
    }

    public function test_two_thousand_synthetic_matches_have_varied_results_and_quality_advantages(): void
    {
        $engine = new FootballMatchEngine;
        $summary = [];
        foreach (['balanced' => 1000, 'strong_home' => 250, 'strong_away' => 250, 'high_effort' => 250, 'exhausted_home' => 250] as $scenario => $count) {
            $sum = ['home_goals' => 0, 'away_goals' => 0, 'home_wins' => 0, 'away_wins' => 0, 'draws' => 0, 'nil_nil' => 0, 'five_plus' => 0, 'fitness' => 0, 'shots' => 0, 'red_cards' => 0];
            $scorelines = [];
            for ($index = 0; $index < $count; $index++) {
                $input = MatchFixture::make('distribution-'.$scenario.'-'.$index,
                    $scenario === 'strong_home' ? 90 : ($scenario === 'strong_away' ? 35 : 65),
                    $scenario === 'strong_away' ? 90 : ($scenario === 'strong_home' ? 35 : 65));
                if ($scenario === 'high_effort') {
                    $input['home']['tactics']['pressure'] = 'high';
                    $input['home']['tactics']['tempo'] = 'fast';
                }
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
                $sum['home_goals'] += $home;
                $sum['away_goals'] += $away;
                $sum[$home > $away ? 'home_wins' : ($home < $away ? 'away_wins' : 'draws')]++;
                $sum['nil_nil'] += (int) ($home + $away === 0);
                $sum['five_plus'] += (int) ($home + $away >= 5);
                $sum['fitness'] += array_sum(array_column($result['home']['players'], 'final_fitness'));
                $sum['shots'] += $result['home']['stats']['shots'] + $result['away']['stats']['shots'];
                $sum['red_cards'] += $result['home']['stats']['red_cards'] + $result['away']['stats']['red_cards'];
                $scorelines[$home.':'.$away] = true;
                $this->assertGreaterThanOrEqual(0, $home);
                $this->assertLessThanOrEqual($result['home']['stats']['shots_on_target'], $home);
                $this->assertEqualsWithDelta(100, $result['home']['stats']['possession'] + $result['away']['stats']['possession'], 0.000001);
            }
            $sum['scorelines'] = count($scorelines);
            $sum['average_fitness'] = $sum['fitness'] / $count;
            $summary[$scenario] = $sum;
        }
        $balanced = $summary['balanced'];
        $this->assertGreaterThan(15, $balanced['scorelines']);
        $this->assertGreaterThan(10, $balanced['nil_nil']);
        $this->assertGreaterThan(20, $balanced['five_plus']);
        $this->assertGreaterThan(100, $balanced['draws']);
        $this->assertLessThan(450, $balanced['draws']);
        $this->assertLessThan(150, abs($balanced['home_wins'] - $balanced['away_wins']));
        $this->assertGreaterThan(1000, $balanced['home_goals'] + $balanced['away_goals']);
        $this->assertLessThan(5000, $balanced['home_goals'] + $balanced['away_goals']);
        $this->assertGreaterThan(10000, $balanced['shots']);
        $this->assertLessThan(40000, $balanced['shots']);
        $this->assertGreaterThan(0, $balanced['red_cards']);
        $this->assertLessThan(2000, $balanced['red_cards']);
        $this->assertGreaterThan(175, $summary['strong_home']['home_wins']);
        $this->assertGreaterThan(175, $summary['strong_away']['away_wins']);
        $this->assertGreaterThan(150, $summary['exhausted_home']['away_wins']);
        $this->assertLessThan($balanced['average_fitness'], $summary['high_effort']['average_fitness']);
    }

    public function test_match_stops_when_a_team_has_fewer_than_seven_players(): void
    {
        $input = MatchFixture::make('abandon-21');
        $input['rules'] = ['max_substitutions' => 0];
        foreach (['home', 'away'] as $side) {
            $input[$side]['substitutes'] = [];
            $input[$side]['tactics']['pressure'] = 'high';
            foreach ($input[$side]['starters'] as &$player) {
                $player['discipline'] = 0;
            }
            unset($player);
        }
        $result = (new FootballMatchEngine)->simulate($input);
        $this->assertSame('abandoned', $result['status']);
        $this->assertSame(85, $result['minutes']);
        $this->assertSame(5, $result['home']['stats']['red_cards']);
        $abandoned = array_values(array_filter($result['events'], fn ($event) => $event['type'] === 'abandoned'));
        $this->assertCount(1, $abandoned);
        $this->assertSame('fewer_than_seven_players', $abandoned[0]['data']['reason']);
        $this->assertLessThanOrEqual(85, max(array_column($result['events'], 'minute')));
    }

    public function test_invalid_snapshots_fail_instead_of_silently_simulating(): void
    {
        $cases = [];
        $input = MatchFixture::make();
        $input['engine_version'] = 'unknown';
        $cases[] = $input;
        $input = MatchFixture::make();
        $input['seed'] = [];
        $cases[] = $input;
        $input = MatchFixture::make();
        $input['home']['starters'][0]['id'] = $input['away']['starters'][0]['id'];
        $cases[] = $input;
        $input = MatchFixture::make();
        $input['home']['starters'][1]['finishing'] = 101;
        $cases[] = $input;
        $input = MatchFixture::make();
        array_pop($input['home']['starters']);
        $cases[] = $input;
        $input = MatchFixture::make();
        $input['home']['tactics']['tempo'] = 'invalid';
        $cases[] = $input;
        $input = MatchFixture::make();
        $input['home_context'] = ['attendance' => 200, 'capacity' => 100, 'ticket_price_cents' => 1];
        $cases[] = $input;
        $input = MatchFixture::make();
        $input['home']['starters'][0]['available'] = false;
        $cases[] = $input;
        foreach ($cases as $case) {
            try {
                (new FootballMatchEngine)->simulate($case);
                $this->fail('An invalid snapshot was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }
}
