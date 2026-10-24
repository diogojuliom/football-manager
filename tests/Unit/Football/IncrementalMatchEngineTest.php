<?php

namespace Tests\Unit\Football;

use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\V4\Simulation;
use App\Domain\Tactics\Tactics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class IncrementalMatchEngineTest extends TestCase
{
    private function input(string $seed = 'incremental'): array
    {
        $input = MatchFixture::make($seed);
        $input['engine_version'] = Simulation::VERSION;
        $input['rules'] = ['max_substitutions' => 3, 'max_substitution_windows' => 3];
        $input['home']['controller'] = 'human';
        $input['away']['controller'] = 'ai';

        return $input;
    }

    public function test_chunk_sizes_pauses_and_state_restoration_produce_the_same_match(): void
    {
        $input = $this->input();
        $minuteByMinute = new Simulation((new \App\Domain\Football\Engine\MatchInput($input))->data);
        while (! in_array($minuteByMinute->state()['status'], ['finished', 'abandoned'], true)) {
            if ($minuteByMinute->state()['status'] === 'half_time') {
                $minuteByMinute->resumeHalfTime();
            }
            $minuteByMinute->advance(1);
            $minuteByMinute = new Simulation((new \App\Domain\Football\Engine\MatchInput($input))->data, $minuteByMinute->state());
        }

        $chunked = new Simulation((new \App\Domain\Football\Engine\MatchInput($input))->data);
        foreach ([7, 3, 11, 2, 19, 30, 25] as $chunk) {
            if ($chunked->state()['status'] === 'half_time') {
                $chunked->resumeHalfTime();
            }
            $chunked->advance($chunk);
            if (in_array($chunked->state()['status'], ['finished', 'abandoned'], true)) {
                break;
            }
        }
        while (! in_array($chunked->state()['status'], ['finished', 'abandoned'], true)) {
            if ($chunked->state()['status'] === 'half_time') {
                $chunked->resumeHalfTime();
            }
            $chunked->advance(20);
        }

        $fast = new Simulation((new \App\Domain\Football\Engine\MatchInput($input))->data);
        $fast->advance(120, true);

        $this->assertSame($minuteByMinute->result(), $chunked->result());
        $this->assertSame($minuteByMinute->result(), $fast->result());
        $this->assertGreaterThanOrEqual(91, $chunked->result()['minutes']);
        $this->assertLessThanOrEqual(95, $chunked->result()['minutes']);
    }

    public function test_decisions_only_change_the_future_and_are_exactly_replayable(): void
    {
        $input = (new \App\Domain\Football\Engine\MatchInput($this->input('decision-seed')))->data;
        $simulation = new Simulation($input);
        $simulation->advance(45);
        $simulation->resumeHalfTime();
        $simulation->advance(20);
        $eventsBefore = $simulation->state()['events'];
        $simulation->applyDecision('home', 'tactics', [
            'formation' => '4-2-4', 'style' => 'total_attack',
            'attack_direction' => 'wings', 'marking' => 'very_heavy',
        ]);
        while (! in_array($simulation->state()['status'], ['finished', 'abandoned'], true)) {
            $simulation->advance(20, true);
        }
        $result = $simulation->result();
        $this->assertSame($eventsBefore, array_slice($result['events'], 0, count($eventsBefore)));
        $humanDecision = collect($result['decisions'])->firstWhere('actor', 'human');
        $this->assertSame(65, $humanDecision['minute']);
        $this->assertSame('4-2-4', $result['home']['final_tactics']['formation']);

        $input['decisions'] = [['minute' => 65, 'side' => 'home', 'type' => 'tactics', 'payload' => [
            'formation' => '4-2-4', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'very_heavy',
        ]]];
        $this->assertSame($result, (new FootballMatchEngine)->simulate($input));
    }

    public function test_substitution_changes_active_team_and_obeys_limits(): void
    {
        $input = (new \App\Domain\Football\Engine\MatchInput($this->input('substitution-seed')))->data;
        $simulation = new Simulation($input);
        $simulation->advance(10);
        $before = $simulation->state();
        $out = $before['teams']['home']['active'][1];
        $in = $before['teams']['home']['bench'][1];
        $simulation->applyDecision('home', 'substitution', ['out' => $out, 'in' => $in]);
        $after = $simulation->state();
        $this->assertSame($in, $after['teams']['home']['active'][1]);
        $this->assertNotContains($in, $after['teams']['home']['bench']);
        $this->assertSame(1, $after['teams']['home']['stats']['substitutions']);
        $this->assertSame(1, $after['teams']['home']['substitution_windows']);
    }

    public function test_all_formations_are_available_during_the_match(): void
    {
        $input = (new \App\Domain\Football\Engine\MatchInput($this->input('formations-live')))->data;
        $simulation = new Simulation($input);

        foreach (array_keys(Tactics::FORMATIONS) as $formation) {
            $state = $simulation->applyDecision('home', 'tactics', [
                'formation' => $formation, 'style' => 'balanced',
                'attack_direction' => 'balanced', 'marking' => 'light',
            ]);
            $this->assertSame($formation, $state['teams']['home']['tactics']['formation']);
        }

        $this->assertCount(21, Tactics::FORMATIONS);
    }

    public function test_tactical_instructions_change_future_fitness_and_sports_statistics(): void
    {
        $input = (new \App\Domain\Football\Engine\MatchInput($this->input('tactical-effects')))->data;
        $balanced = new Simulation($input);
        $attacking = new Simulation($input);
        $balanced->applyDecision('home', 'tactics', [
            'formation' => '4-4-2', 'style' => 'balanced', 'attack_direction' => 'balanced', 'marking' => 'light',
        ]);
        $attacking->applyDecision('home', 'tactics', [
            'formation' => '4-2-4', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'very_heavy',
        ]);
        while (! in_array($balanced->state()['status'], ['finished', 'abandoned'], true)) {
            $balanced->advance(120, true);
        }
        while (! in_array($attacking->state()['status'], ['finished', 'abandoned'], true)) {
            $attacking->advance(120, true);
        }

        $normal = $balanced->result();
        $aggressive = $attacking->result();
        $this->assertNotSame(array_column($normal['home']['players'], 'final_fitness'), array_column($aggressive['home']['players'], 'final_fitness'));
        $this->assertNotSame($normal['home']['stats'], $aggressive['home']['stats']);
    }

    public function test_multiple_substitutions_in_one_pause_use_one_window_and_later_windows_are_blocked(): void
    {
        $source = $this->input('windows');
        $source['rules'] = ['max_substitutions' => 5, 'max_substitution_windows' => 1];
        $input = (new \App\Domain\Football\Engine\MatchInput($source))->data;
        $simulation = new Simulation($input);
        $simulation->advance(10);
        for ($slot = 1; $slot <= 2; $slot++) {
            $state = $simulation->state();
            $simulation->applyDecision('home', 'substitution', [
                'out' => $state['teams']['home']['active'][$slot],
                'in' => $state['teams']['home']['bench'][1],
            ]);
        }
        $this->assertSame(1, $simulation->state()['teams']['home']['substitution_windows']);
        $simulation->advance(1);
        $state = $simulation->state();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limite de janelas');
        $simulation->applyDecision('home', 'substitution', [
            'out' => $state['teams']['home']['active'][3],
            'in' => $state['teams']['home']['bench'][1],
        ]);
    }

    public function test_ai_uses_the_same_tactical_model_to_chase_a_result(): void
    {
        $input = (new \App\Domain\Football\Engine\MatchInput($this->input('ai-live')))->data;
        $simulation = new Simulation($input);
        $simulation->advance(69, true);
        $state = $simulation->state();
        $state['teams']['home']['stats']['goals'] = 2;
        $state['teams']['away']['stats']['goals'] = 0;
        $simulation = new Simulation($input, $state);
        $simulation->advance(1);

        $away = $simulation->state()['teams']['away'];
        $this->assertSame('4-2-4', $away['tactics']['formation']);
        $this->assertSame('total_attack', $away['tactics']['style']);
        $this->assertNotNull(collect($simulation->state()['decisions'])->first(fn (array $decision) => $decision['actor'] === 'ai' && $decision['type'] === 'tactics'));
    }
}
