<?php

namespace Tests\Unit\Football;

use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\V4\Simulation as HistoricalSimulation;
use App\Domain\Football\Engine\V5\Simulation;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class HydrationBreakTest extends TestCase
{
    public function test_enabled_breaks_pause_once_at_30_and_75_and_add_three_minutes_to_each_half(): void
    {
        $input = $this->input('hydration-enabled');
        $simulation = new Simulation($input);
        $simulation->advance(120);
        $first = $simulation->state();

        $this->assertSame('hydration_break', $first['status']);
        $this->assertSame(30, $first['tick']);
        $this->assertSame(30, $first['minute']);
        $this->assertSame('active', $first['hydration_breaks']['first_half']['status']);
        $this->assertSame(3, $first['first_half_added_time']);
        $this->assertSame($first, $simulation->advance(10));
        $this->assertCount(1, $this->events($first, 'hydration_break_start'));

        $eventsBefore = $first['events'];
        $rngBefore = $first['rng_counter'];
        $out = $first['teams']['home']['active'][1];
        $in = $first['teams']['home']['bench'][1];
        $simulation->applyDecision('home', 'tactics', [
            'formation' => '4-3-3', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'heavy',
        ]);
        $simulation->applyDecision('home', 'substitution', ['out' => $out, 'in' => $in]);
        $adjusted = $simulation->state();
        $this->assertSame($rngBefore, $adjusted['rng_counter']);
        $this->assertSame($eventsBefore, array_slice($adjusted['events'], 0, count($eventsBefore)));
        $this->assertSame(1, $adjusted['teams']['home']['substitution_windows']);

        $simulation->resumeHydrationBreak();
        $afterResume = $simulation->state();
        $this->assertSame('playing', $afterResume['status']);
        $this->assertSame(30, $afterResume['tick']);
        $this->assertSame($rngBefore, $afterResume['rng_counter']);
        $this->assertSame('completed', $afterResume['hydration_breaks']['first_half']['status']);

        $simulation->advance(120);
        $halfTime = $simulation->state();
        $this->assertSame('half_time', $halfTime['status']);
        $this->assertSame(48, $halfTime['tick']);
        $this->assertSame(45, $halfTime['minute']);
        $this->assertSame(3, $halfTime['added_minute']);
        $halfTimeEvent = $this->events($halfTime, 'half_time')[0];
        $this->assertSame(45, $halfTimeEvent['minute']);
        $this->assertSame(3, $halfTimeEvent['added_minute']);

        $simulation->resumeHalfTime();
        $simulation->advance(120);
        $second = $simulation->state();
        $this->assertSame('hydration_break', $second['status']);
        $this->assertSame(78, $second['tick']);
        $this->assertSame(75, $second['minute']);
        $this->assertSame('active', $second['hydration_breaks']['second_half']['status']);

        $simulation->resumeHydrationBreak();
        $simulation->advance(120, true);
        $finished = $simulation->state();
        $result = $simulation->result();
        $this->assertSame('finished', $finished['status']);
        $this->assertSame(90 + $finished['added_time'], $finished['tick']);
        $this->assertSame(3, $finished['first_half_added_time']);
        $this->assertSame($finished['base_added_time'] + 3, $finished['second_half_added_time']);
        $this->assertCount(2, $this->events($finished, 'hydration_break_start'));
        $this->assertCount(2, $this->events($finished, 'hydration_break_end'));
        $this->assertSame([30, 75], array_column($this->events($finished, 'hydration_break_start'), 'minute'));
        $fullTime = $this->events($finished, 'full_time')[0];
        $this->assertSame(90, $fullTime['minute']);
        $this->assertSame($finished['second_half_added_time'], $fullTime['added_minute']);

        $input['decisions'] = array_map(fn (array $decision): array => [
            'tick' => $decision['tick'], 'minute' => $decision['minute'], 'added_minute' => $decision['added_minute'],
            'side' => $decision['side'], 'type' => $decision['type'], 'payload' => $decision['payload'],
        ], array_values(array_filter($finished['decisions'], fn (array $decision): bool => $decision['actor'] === 'human')));
        $this->assertSame($result, (new FootballMatchEngine)->simulate($input));
    }

    public function test_disabled_breaks_do_not_change_the_clock_or_emit_hydration_events(): void
    {
        $input = $this->input('hydration-disabled', false);
        $simulation = new Simulation($input);
        $simulation->advance(120, true);
        $state = $simulation->state();

        $this->assertSame('finished', $state['status']);
        $this->assertSame(0, $state['first_half_added_time']);
        $this->assertSame($state['base_added_time'], $state['second_half_added_time']);
        $this->assertSame(90 + $state['base_added_time'], $state['tick']);
        $this->assertSame('disabled', $state['hydration_breaks']['first_half']['status']);
        $this->assertSame('disabled', $state['hydration_breaks']['second_half']['status']);
        $this->assertSame([], $this->events($state, 'hydration_break_start'));
        $this->assertSame($simulation->result(), (new FootballMatchEngine)->simulate($input));
    }

    public function test_ai_fast_simulation_chunks_and_restoration_are_exactly_deterministic(): void
    {
        $input = $this->input('hydration-ai-determinism', true, true);
        $expected = (new FootballMatchEngine)->simulate($input);

        $chunked = new Simulation($input);
        $chunks = [17, 50, 2, 29, 1, 40];
        $cursor = 0;
        while (! in_array($chunked->state()['status'], ['finished', 'abandoned'], true)) {
            if ($chunked->state()['status'] === 'hydration_break') {
                $chunked->resumeHydrationBreak();
            } elseif ($chunked->state()['status'] === 'half_time') {
                $chunked->resumeHalfTime();
            }
            $chunked->advance($chunks[$cursor++ % count($chunks)]);
            $chunked = new Simulation($input, $chunked->state());
        }

        $fast = new Simulation($input);
        $fast->advance(120, true);
        $this->assertSame($expected, $chunked->result());
        $this->assertSame($expected, $fast->result());
        $this->assertSame($expected, (new FootballMatchEngine)->simulate($input));
        $this->assertCount(2, $this->events($fast->state(), 'hydration_break_start'));
        $this->assertNotEmpty(array_filter($expected['decisions'], fn (array $decision): bool => $decision['actor'] === 'ai'));
    }

    public function test_published_v4_replay_remains_unchanged(): void
    {
        $input = MatchFixture::make('published-v4-hydration-compatibility');
        $input['engine_version'] = HistoricalSimulation::VERSION;
        $input['rules'] = ['max_substitutions' => 3, 'max_substitution_windows' => 3];
        $input['home']['controller'] = 'human';
        $input['away']['controller'] = 'ai';
        $normalized = (new MatchInput($input))->data;

        $first = (new FootballMatchEngine)->simulate($normalized);
        $second = (new FootballMatchEngine)->simulate($normalized);
        $this->assertSame($first, $second);
        $this->assertSame('4.0.0', $first['engine_version']);
        $this->assertArrayNotHasKey('first_half_added_time', $first);
        $this->assertNotContains('hydration_break_start', array_column($first['events'], 'type'));
    }

    private function input(string $seed, bool $enabled = true, bool $aiOnly = false): array
    {
        $input = MatchFixture::make($seed);
        $input['engine_version'] = Simulation::VERSION;
        $input['rules'] = [
            'max_substitutions' => 5,
            'max_substitution_windows' => 3,
            'hydration_breaks_enabled' => $enabled,
        ];
        $input['home']['controller'] = $aiOnly ? 'ai' : 'human';
        $input['away']['controller'] = 'ai';

        return (new MatchInput($input))->data;
    }

    private function events(array $state, string $type): array
    {
        return array_values(array_filter($state['events'], fn (array $event): bool => $event['type'] === $type));
    }
}
