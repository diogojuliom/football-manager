<?php

namespace Tests\Unit\Football;

use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\V4\Simulation as V4Simulation;
use App\Domain\Football\Engine\V5\Simulation as V5Simulation;
use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Engine\V6\Simulation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\MatchFixture;

class DynamicAddedTimeTest extends TestCase
{
    public function test_period_without_lost_time_has_zero_added_time(): void
    {
        $simulation = new Simulation($this->input('no-lost-time', false, $this->fixedRules()));
        $simulation->advance(45);

        $state = $simulation->state();
        $this->assertSame('half_time', $state['status']);
        $this->assertSame(0, $state['lost_time']['first_half']['seconds']);
        $this->assertSame(0, $state['first_half_added_time']);
        $this->assertSame(45, $state['tick']);
    }

    public function test_hydration_is_counted_once_in_each_period_and_can_be_disabled(): void
    {
        $enabled = $this->complete(new Simulation($this->input('hydration-only', true, $this->fixedRules())));
        $this->assertSame(180, $enabled['lost_time']['first_half']['seconds']);
        $this->assertSame(180, $enabled['lost_time']['second_half']['seconds']);
        $this->assertSame(3, $enabled['first_half_added_time']);
        $this->assertSame(3, $enabled['second_half_added_time']);
        $this->assertSame(96, $enabled['tick']);
        $this->assertCount(2, $this->events($enabled, 'hydration_break_start'));

        $disabled = $this->complete(new Simulation($this->input('hydration-disabled-v6', false, $this->fixedRules())));
        $this->assertSame(0, $disabled['lost_time']['first_half']['seconds']);
        $this->assertSame(0, $disabled['lost_time']['second_half']['seconds']);
        $this->assertSame(90, $disabled['tick']);
        $this->assertSame([], $this->events($disabled, 'hydration_break_start'));
    }

    public function test_real_events_sum_durations_and_group_substitutions_and_cards(): void
    {
        $rules = $this->fixedRules(goal: 40, substitution: 25, injury: 70, card: 20);
        $simulation = new Simulation($this->input('event-accounting', false, $rules));
        $this->emit($simulation, 'goal', 'home', 'home-10');
        $this->emit($simulation, 'goal', 'away', 'away-10');
        $this->emit($simulation, 'substitution', 'home', 'home-11');
        $this->emit($simulation, 'substitution', 'away', 'away-11');
        $this->emit($simulation, 'injury', 'home', 'home-4');
        $this->emit($simulation, 'yellow_card', 'away', 'away-4');
        $this->emit($simulation, 'red_card', 'away', 'away-4');

        $lost = $simulation->state()['lost_time']['first_half'];
        $this->assertSame(195, $lost['seconds']);
        $this->assertCount(5, $lost['contributions']);
        $substitutions = array_values(array_filter($lost['contributions'], fn (array $item): bool => $item['type'] === 'substitution'));
        $discipline = array_values(array_filter($lost['contributions'], fn (array $item): bool => $item['type'] === 'yellow_card'));
        $this->assertCount(1, $substitutions);
        $this->assertCount(2, $substitutions[0]['event_sequences']);
        $this->assertCount(1, $discipline);
        $this->assertCount(2, $discipline[0]['event_sequences']);
    }

    public function test_substitution_during_hydration_does_not_add_time(): void
    {
        $simulation = new Simulation($this->input('hydration-substitution-v6', true, $this->fixedRules(substitution: 35)));
        $simulation->advance(30);
        $state = $simulation->state();
        $simulation->applyDecision('home', 'substitution', [
            'out' => $state['teams']['home']['active'][1],
            'in' => $state['teams']['home']['bench'][1],
        ]);

        $lost = $simulation->state()['lost_time']['first_half'];
        $this->assertSame(180, $lost['seconds']);
        $this->assertCount(1, $lost['contributions']);
        $this->assertSame('hydration_break_start', $lost['contributions'][0]['type']);
    }

    public function test_interruption_after_announcement_extends_minimum_without_loop(): void
    {
        $rules = $this->fixedRules(goal: 60, injury: 120);
        $simulation = new Simulation($this->input('added-time-extension', false, $rules));
        $simulation->advance(44);
        $this->emit($simulation, 'goal', 'home', 'home-10');
        $simulation->advance(1);
        $announced = $simulation->state();
        $announcedMinutes = $announced['first_half_added_time'];
        $announcedSeconds = $announced['lost_time']['first_half']['seconds'];
        $this->assertGreaterThanOrEqual(1, $announcedMinutes);

        $this->emit($simulation, 'injury', 'away', 'away-4');
        $state = $simulation->state();
        $this->assertSame($announcedSeconds + 120, $state['lost_time']['first_half']['seconds']);
        $this->assertSame($announcedMinutes, $state['lost_time']['first_half']['announced_minutes']);
        $this->assertSame($announcedMinutes + 2, $state['first_half_added_time']);
        $this->assertCount(1, $state['lost_time']['first_half']['extensions']);
        $this->assertCount(1, $this->events($state, 'added_time_extended'));

        $simulation->advance(20);
        $this->assertSame('half_time', $simulation->state()['status']);
        $this->assertSame(45 + $announcedMinutes + 2, $simulation->state()['tick']);
    }

    public function test_same_match_is_deterministic_across_chunks_restores_and_fast_simulation(): void
    {
        $input = $this->input('dynamic-determinism', true);
        $expected = (new FootballMatchEngine)->simulate($input);

        foreach ([[1], [5], [13, 2, 7, 1, 19]] as $chunks) {
            $simulation = new Simulation($input);
            $cursor = 0;
            while (! in_array($simulation->state()['status'], ['finished', 'abandoned'], true)) {
                $this->resume($simulation);
                $simulation->advance($chunks[$cursor++ % count($chunks)]);
                $simulation = new Simulation($input, $simulation->state());
            }
            $this->assertSame($expected, $simulation->result());
        }

        $fast = new Simulation($input);
        $fast->advance(120, true);
        $this->assertSame($expected, $fast->result());
    }

    public function test_state_restoration_preserves_lost_time_before_and_during_added_time(): void
    {
        $input = $this->input('dynamic-recovery', true);
        $simulation = new Simulation($input);
        $simulation->advance(35);
        $restored = new Simulation($input, $simulation->state());
        $this->assertSame($simulation->state(), $restored->state());

        $this->resume($restored);
        while ($restored->state()['period'] === 1 && $restored->state()['added_minute'] === 0) {
            $restored->advance(1);
            $this->resume($restored);
        }
        $addedState = $restored->state();
        $secondRestore = new Simulation($input, $addedState);
        $this->assertSame($addedState, $secondRestore->state());
        $this->assertSame($this->complete($restored), $this->complete($secondRestore));
    }

    public function test_ai_only_fast_simulation_uses_the_same_dynamic_clock_deterministically(): void
    {
        $input = $this->input('ai-dynamic-added-time', true, null, true);
        $expected = (new FootballMatchEngine)->simulate($input);
        $fast = new Simulation($input);
        $fast->advance(120, true);

        $this->assertSame($expected, $fast->result());
        $this->assertSame($expected, (new FootballMatchEngine)->simulate($input));
        $this->assertCount(2, $this->events($fast->state(), 'hydration_break_start'));
        $this->assertNotEmpty(array_filter($expected['decisions'], fn (array $decision): bool => $decision['actor'] === 'ai'));
    }

    public function test_v4_and_v5_results_remain_byte_for_byte_compatible(): void
    {
        foreach ([V4Simulation::VERSION, V5Simulation::VERSION] as $version) {
            $raw = MatchFixture::make('historical-'.str_replace('.', '-', $version));
            $raw['engine_version'] = $version;
            $raw['rules'] = ['max_substitutions' => 3, 'max_substitution_windows' => 3];
            if ($version === V5Simulation::VERSION) {
                $raw['rules']['hydration_breaks_enabled'] = true;
            }
            $raw['home']['controller'] = 'human';
            $raw['away']['controller'] = 'ai';
            $input = (new MatchInput($raw))->data;
            $published = $version === V4Simulation::VERSION ? (new V4Simulation($input))->run() : (new V5Simulation($input))->run();
            $this->assertSame($published, (new FootballMatchEngine)->simulate($input));
            $this->assertArrayNotHasKey('lost_time', $published);
        }
    }

    public function test_many_interruptions_have_bounded_non_negative_clock(): void
    {
        $rules = $this->fixedRules(goal: 50, substitution: 35, injury: 120, card: 25);
        $simulation = new Simulation($this->input('stress-added-time', false, $rules));
        for ($minute = 0; $minute < 20; $minute++) {
            $simulation->advance(1);
            $this->emit($simulation, 'goal', 'home', 'home-10');
            $this->emit($simulation, 'injury', 'away', 'away-4');
            $this->emit($simulation, 'yellow_card', 'away', 'away-5');
        }
        $state = $this->complete($simulation);
        $this->assertSame('finished', $state['status']);
        $this->assertGreaterThanOrEqual(0, $state['added_time']);
        $this->assertLessThan(200, $state['tick']);
        $this->assertSame(count($state['events']), count(array_unique(array_column($state['events'], 'sequence'))));
    }

    private function input(string $seed, bool $hydration, ?array $rules = null, bool $aiOnly = false): array
    {
        $input = MatchFixture::make($seed);
        $input['engine_version'] = Simulation::VERSION;
        $input['rules'] = [
            'max_substitutions' => 5,
            'max_substitution_windows' => 3,
            'hydration_breaks_enabled' => $hydration,
            'dynamic_added_time_enabled' => true,
            'added_time_rules' => $rules ?? AddedTimeRules::DEFAULTS,
        ];
        $input['home']['controller'] = $aiOnly ? 'ai' : 'human';
        $input['away']['controller'] = 'ai';

        return (new MatchInput($input))->data;
    }

    private function fixedRules(int $goal = 0, int $substitution = 0, int $injury = 0, int $card = 0): array
    {
        return array_replace(AddedTimeRules::DEFAULTS, [
            'goal_celebration_seconds' => [$goal, $goal],
            'substitution_seconds' => [$substitution, $substitution],
            'injury_seconds' => [$injury, $injury],
            'card_seconds' => [$card, $card],
            'var_seconds' => [0, 0],
            'restart_delay_seconds' => [0, 0],
        ]);
    }

    private function emit(Simulation $simulation, string $type, ?string $side = null, ?string $player = null): void
    {
        (new ReflectionMethod($simulation, 'event'))->invoke($simulation, $type, $side, $player, []);
    }

    private function resume(Simulation $simulation): void
    {
        if ($simulation->state()['status'] === 'hydration_break') {
            $simulation->resumeHydrationBreak();
        } elseif ($simulation->state()['status'] === 'half_time') {
            $simulation->resumeHalfTime();
        }
    }

    private function complete(Simulation $simulation): array
    {
        $guard = 0;
        while (! in_array($simulation->state()['status'], ['finished', 'abandoned'], true)) {
            $this->resume($simulation);
            $simulation->advance(120);
            if (++$guard > 20) {
                $this->fail('A simulação não encerrou dentro do limite seguro.');
            }
        }

        return $simulation->state();
    }

    private function events(array $state, string $type): array
    {
        return array_values(array_filter($state['events'], fn (array $event): bool => $event['type'] === $type));
    }
}
