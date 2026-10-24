<?php

namespace Tests\Unit\Football;

use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V4\Simulation;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class IncrementalDeterminismTest extends TestCase
{
    public function test_fixed_seeds_decisions_speeds_chunks_pauses_and_recoveries_are_identical(): void
    {
        $scenarios = [
            'continuous-1x' => ['chunks' => [120], 'speed' => 1.0, 'restores' => [], 'pauses' => []],
            'minute-0.5x' => ['chunks' => [1], 'speed' => 0.5, 'restores' => [35, 65], 'pauses' => [12, 52, 78]],
            'five-minutes-0.75x' => ['chunks' => [5], 'speed' => 0.75, 'restores' => [35, 65], 'pauses' => [20, 70]],
            'variable-1.25x' => ['chunks' => [2, 9, 4, 16, 5], 'speed' => 1.25, 'restores' => [35, 65], 'pauses' => [17, 73]],
            'five-minutes-1.5x' => ['chunks' => [5], 'speed' => 1.5, 'restores' => [35, 65], 'pauses' => [25, 75]],
            'variable-1.75x' => ['chunks' => [7, 3, 11, 2, 19, 5, 13], 'speed' => 1.75, 'restores' => [35, 65], 'pauses' => [9, 41, 83]],
            'variable-2x' => ['chunks' => [5, 1, 12, 4, 8], 'speed' => 2.0, 'restores' => [35, 65], 'pauses' => [30, 80]],
        ];

        foreach (['rng-fixture-alpha', 'rng-fixture-beta'] as $seed) {
            $input = $this->input($seed);
            $replayInput = $input;
            $replayInput['decisions'] = $this->decisions();
            $canonical = (new FootballMatchEngine)->simulate($replayInput);
            $baseline = null;

            foreach ($scenarios as $name => $scenario) {
                $this->assertContains($scenario['speed'], [0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0]);
                $this->assertArrayNotHasKey('speed', $input, "A velocidade de {$name} vazou para as regras esportivas.");
                $execution = $this->runIncrementally($input, $scenario['chunks'], $scenario['restores'], $scenario['pauses']);

                $this->assertSame($canonical, $execution['result'], "O resultado completo divergiu em {$seed}/{$name}.");
                $this->assertSame(ReplayHash::of($canonical), ReplayHash::of($execution['result']));
                $this->assertSame($canonical['events'], $execution['result']['events']);
                $this->assertSame($canonical['decisions'], $execution['result']['decisions']);
                $this->assertSame($canonical['minutes'], $execution['result']['minutes']);
                $this->assertSame($canonical['home']['stats'], $execution['result']['home']['stats']);
                $this->assertSame($canonical['away']['stats'], $execution['result']['away']['stats']);

                if ($baseline === null) {
                    $baseline = $execution;
                } else {
                    $this->assertSame($baseline['state']['rng_counter'], $execution['state']['rng_counter'], "O RNG divergiu em {$seed}/{$name}.");
                    $this->assertSame($baseline['state'], $execution['state'], "O estado final divergiu em {$seed}/{$name}.");
                }
            }

            $aiDecisions = array_values(array_filter($canonical['decisions'], fn (array $decision): bool => $decision['actor'] === 'ai'));
            $this->assertNotEmpty($aiDecisions, "A fixture {$seed} não exercitou decisões da IA.");
            $this->assertGreaterThan(0, $baseline['state']['rng_counter']);
        }
    }

    public function test_mysql_json_player_key_order_does_not_change_a_resumed_result(): void
    {
        $input = $this->input('mysql-json-order');
        $simulation = new Simulation($input);
        $simulation->advance(35);
        $state = $simulation->state();
        $reordered = $state;
        foreach (['home', 'away'] as $side) {
            krsort($reordered['teams'][$side]['players']);
            $this->assertNotSame(
                array_keys($state['teams'][$side]['players']),
                array_keys($reordered['teams'][$side]['players']),
            );
        }

        $continuous = new Simulation($input, $state);
        $restored = new Simulation($input, $reordered);
        while (! in_array($continuous->state()['status'], ['finished', 'abandoned'], true)) {
            $continuous->advance(120, true);
        }
        while (! in_array($restored->state()['status'], ['finished', 'abandoned'], true)) {
            $restored->advance(120, true);
        }

        $this->assertSame($continuous->result(), $restored->result());
        $this->assertSame($continuous->state()['rng_counter'], $restored->state()['rng_counter']);
    }

    private function input(string $seed): array
    {
        $input = MatchFixture::make($seed);
        $input['engine_version'] = Simulation::VERSION;
        $input['rules'] = ['max_substitutions' => 3, 'max_substitution_windows' => 3];
        $input['home']['controller'] = 'human';
        $input['away']['controller'] = 'ai';

        return (new MatchInput($input))->data;
    }

    private function decisions(): array
    {
        return [
            ['minute' => 35, 'side' => 'home', 'type' => 'tactics', 'payload' => [
                'formation' => '4-3-3', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'heavy',
            ]],
            ['minute' => 65, 'side' => 'home', 'type' => 'tactics', 'payload' => [
                'formation' => '5-4-1', 'style' => 'counter', 'attack_direction' => 'center', 'marking' => 'light',
            ]],
        ];
    }

    private function runIncrementally(array $input, array $chunks, array $restores, array $pauses): array
    {
        $simulation = new Simulation($input);
        $decisions = $this->decisions();
        $decisionCursor = 0;
        $chunkCursor = 0;
        $handledRestores = [];
        $handledPauses = [];

        while (! in_array($simulation->state()['status'], ['finished', 'abandoned'], true)) {
            $state = $simulation->state();
            if ($state['status'] === 'half_time') {
                $simulation->resumeHalfTime();
                $state = $simulation->state();
            }

            if (in_array($state['minute'], $restores, true) && ! isset($handledRestores[$state['minute']])) {
                $handledRestores[$state['minute']] = true;
                $simulation = $this->restoreFromJson($input, $state);
                $this->assertSame($state['rng_counter'], $simulation->state()['rng_counter']);
                $state = $simulation->state();
            }
            if (in_array($state['minute'], $pauses, true) && ! isset($handledPauses[$state['minute']])) {
                $handledPauses[$state['minute']] = true;
                $beforePause = $state;
                // No call to advance represents any amount of wall-clock time while paused/disconnected.
                $simulation = $this->restoreFromJson($input, $beforePause);
                $this->assertSame($beforePause, $simulation->state());
                $state = $simulation->state();
            }

            while (isset($decisions[$decisionCursor]) && $decisions[$decisionCursor]['minute'] === $state['minute']) {
                $decision = $decisions[$decisionCursor++];
                $simulation->applyDecision($decision['side'], $decision['type'], $decision['payload']);
                $state = $simulation->state();
            }

            $boundaries = [];
            if (isset($decisions[$decisionCursor])) {
                $boundaries[] = $decisions[$decisionCursor]['minute'];
            }
            foreach ([...$restores, ...$pauses] as $boundary) {
                if ($boundary > $state['minute']) {
                    $boundaries[] = $boundary;
                }
            }
            $requested = $chunks[$chunkCursor++ % count($chunks)];
            if ($boundaries !== []) {
                $requested = min($requested, min($boundaries) - $state['minute']);
            }
            $simulation->advance(max(1, $requested));
        }

        $this->assertSame(count($decisions), $decisionCursor);
        foreach ($restores as $minute) {
            $this->assertArrayHasKey($minute, $handledRestores);
        }

        return ['result' => $simulation->result(), 'state' => $simulation->state()];
    }

    private function restoreFromJson(array $input, array $state): Simulation
    {
        $persisted = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return new Simulation($input, $persisted);
    }
}
