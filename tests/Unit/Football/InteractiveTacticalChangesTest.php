<?php

namespace Tests\Unit\Football;

use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\V4\Simulation;
use App\Domain\Tactics\Tactics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\MatchFixture;

class InteractiveTacticalChangesTest extends TestCase
{
    public function test_substitution_at_minute_65_uses_the_new_players_attributes_only_after_the_decision(): void
    {
        $source = $this->source('substitution-at-65');
        foreach (array_keys(Tactics::ATTRIBUTES) as $attribute) {
            $source['home']['starters'][10][$attribute] = 30;
            $source['home']['substitutes'][6][$attribute] = 100;
        }
        $input = (new MatchInput($source))->data;
        $simulation = new Simulation($input);
        $simulation->advance(45);
        $simulation->resumeHalfTime();
        $simulation->advance(20);

        $before = $simulation->state();
        $out = 'home-10';
        $slot = array_search($out, $before['teams']['home']['active'], true);
        $this->assertIsInt($slot);
        $in = $before['teams']['home']['bench'][6];
        $oldFinishing = $this->internal($simulation, 'effective', 'home', $slot, 'finishing');

        $simulation->applyDecision('home', 'substitution', ['out' => $out, 'in' => $in]);
        $after = $simulation->state();

        $this->assertSame(65, $after['minute']);
        $this->assertSame($before['events'], array_slice($after['events'], 0, count($before['events'])));
        $this->assertSame($before['rng_counter'], $after['rng_counter']);
        $this->assertSame($in, $after['teams']['home']['active'][$slot]);
        $this->assertNotContains($out, $after['teams']['home']['active']);
        $this->assertNotContains($in, $after['teams']['home']['bench']);
        $this->assertCount(count($after['teams']['home']['active']), array_unique($after['teams']['home']['active']));
        $this->assertGreaterThan($oldFinishing, $this->internal($simulation, 'effective', 'home', $slot, 'finishing'));

        $result = $this->finish($simulation);
        $players = collect($result['home']['players'])->keyBy('player_id');
        $this->assertSame(65, $players[$out]['minutes']);
        $this->assertSame($result['minutes'] - 65, $players[$in]['minutes']);

        $input['decisions'] = [[
            'minute' => 65,
            'side' => 'home',
            'type' => 'substitution',
            'payload' => ['out' => $out, 'in' => $in],
        ]];
        $this->assertSame($result, (new FootballMatchEngine)->simulate($input));
    }

    public function test_invalid_substitutions_and_limits_are_rejected_without_mutating_state(): void
    {
        $simulation = $this->simulation('invalid-substitutions');
        $state = $simulation->state();

        $this->assertRejectedWithoutMutation($simulation, 'substitution', [
            'out' => $state['teams']['home']['active'][1],
            'in' => 'missing-player',
        ]);
        $this->assertRejectedWithoutMutation($simulation, 'substitution', [
            'out' => $state['teams']['home']['active'][1],
            'in' => $state['teams']['home']['active'][2],
        ]);
        $this->assertRejectedWithoutMutation($simulation, 'substitution', [
            'out' => $state['teams']['home']['active'][1],
            'in' => $state['teams']['home']['bench'][0],
        ]);

        $limitedSource = $this->source('substitution-limit');
        $limitedSource['rules']['max_substitutions'] = 1;
        $limited = new Simulation((new MatchInput($limitedSource))->data);
        $limitedState = $limited->state();
        $limited->applyDecision('home', 'substitution', [
            'out' => $limitedState['teams']['home']['active'][1],
            'in' => $limitedState['teams']['home']['bench'][1],
        ]);
        $limitedState = $limited->state();
        $this->assertRejectedWithoutMutation($limited, 'substitution', [
            'out' => $limitedState['teams']['home']['active'][2],
            'in' => $limitedState['teams']['home']['bench'][1],
        ]);
    }

    public function test_unavailable_or_unfit_players_cannot_enter_the_match_snapshot(): void
    {
        foreach ([['available' => false], ['fitness' => 0]] as $invalidAttributes) {
            $source = $this->source('unavailable-'.implode('-', array_keys($invalidAttributes)));
            $source['home']['substitutes'][1] = $invalidAttributes + $source['home']['substitutes'][1];

            try {
                new MatchInput($source);
                $this->fail('An unavailable player was accepted in the match snapshot.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_switching_from_442_to_433_changes_roles_and_keeps_previous_events_intact(): void
    {
        $simulation = $this->simulation('formation-change');
        $simulation->advance(20);
        $before = $simulation->state();
        $this->assertSame('LW', $this->internal($simulation, 'role', 'home', 5));

        $simulation->applyDecision('home', 'tactics', [
            'formation' => '4-3-3',
            'style' => 'balanced',
            'attack_direction' => 'balanced',
            'marking' => 'light',
        ]);
        $after = $simulation->state();

        $this->assertSame($before['events'], array_slice($after['events'], 0, count($before['events'])));
        $this->assertSame($before['rng_counter'], $after['rng_counter']);
        $this->assertSame('4-3-3', $after['teams']['home']['tactics']['formation']);
        $this->assertSame('DM', $this->internal($simulation, 'role', 'home', 5));
        $this->assertSame('tactical_change', $after['events'][count($before['events'])]['type']);
        $this->assertSame(20, $after['events'][count($before['events'])]['minute']);
    }

    public function test_all_21_formations_produce_valid_unique_lineups_and_internal_strengths(): void
    {
        foreach (array_keys(Tactics::FORMATIONS) as $formation) {
            $simulation = $this->simulation('formation-'.str_replace('-', '', $formation));
            $simulation->applyDecision('home', 'tactics', [
                'formation' => $formation,
                'style' => 'balanced',
                'attack_direction' => 'balanced',
                'marking' => 'light',
            ]);
            $state = $simulation->state();
            $active = $state['teams']['home']['active'];
            $strength = $this->internal($simulation, 'strength', 'home');

            $this->assertCount(11, Tactics::FORMATIONS[$formation]);
            $this->assertSame('GK', Tactics::FORMATIONS[$formation][0]);
            $this->assertCount(11, $active);
            $this->assertCount(11, array_unique($active));
            $this->assertSame($formation, $state['teams']['home']['tactics']['formation']);
            $this->assertGreaterThan(0, $strength['attack']);
            $this->assertGreaterThan(0, $strength['defense']);
            $this->assertGreaterThan(0, $strength['control']);
        }

        $this->assertCount(21, Tactics::FORMATIONS);
    }

    public function test_swapping_two_starters_changes_their_roles_and_positional_adequacy(): void
    {
        $simulation = $this->simulation('position-swap');
        $simulation->advance(5);
        $before = $simulation->state();
        $leftWing = $before['teams']['home']['active'][5];
        $rightWing = $before['teams']['home']['active'][8];
        $leftBefore = $this->internal($simulation, 'effective', 'home', 5, 'passing');
        $rightBefore = $this->internal($simulation, 'effective', 'home', 8, 'passing');
        $swapped = $before['teams']['home']['active'];
        [$swapped[5], $swapped[8]] = [$swapped[8], $swapped[5]];

        $simulation->applyDecision('home', 'positions', ['active' => $swapped]);
        $after = $simulation->state();

        $this->assertSame($before['events'], array_slice($after['events'], 0, count($before['events'])));
        $this->assertSame($rightWing, $after['teams']['home']['active'][5]);
        $this->assertSame($leftWing, $after['teams']['home']['active'][8]);
        $this->assertCount(11, array_unique($after['teams']['home']['active']));
        $this->assertLessThan($leftBefore, $this->internal($simulation, 'effective', 'home', 5, 'passing'));
        $this->assertLessThan($rightBefore, $this->internal($simulation, 'effective', 'home', 8, 'passing'));

        $invalid = $after['teams']['home']['active'];
        $invalid[8] = $invalid[5];
        $this->assertRejectedWithoutMutation($simulation, 'positions', ['active' => $invalid]);
    }

    public function test_live_instructions_change_offensive_defensive_control_fatigue_and_discipline_calculations(): void
    {
        $balanced = $this->simulationWithTactics('factors-balanced', 'balanced', 'balanced', 'light');
        $totalAttack = $this->simulationWithTactics('factors-balanced', 'total_attack', 'balanced', 'light');
        $center = $this->simulationWithTactics('factors-balanced', 'balanced', 'center', 'light');
        $wings = $this->simulationWithTactics('factors-balanced', 'balanced', 'wings', 'light');
        $veryHeavy = $this->simulationWithTactics('factors-balanced', 'balanced', 'balanced', 'very_heavy');
        $baseStrength = $this->internal($balanced, 'strength', 'home');
        $attackingStrength = $this->internal($totalAttack, 'strength', 'home');
        $centerStrength = $this->internal($center, 'strength', 'home');
        $wingStrength = $this->internal($wings, 'strength', 'home');
        $heavyStrength = $this->internal($veryHeavy, 'strength', 'home');

        $this->assertGreaterThan($baseStrength['attack'], $attackingStrength['attack']);
        $this->assertLessThan($baseStrength['defense'], $attackingStrength['defense']);
        $this->assertGreaterThan($baseStrength['control'], $centerStrength['control']);
        $this->assertGreaterThan($baseStrength['attack'], $wingStrength['attack']);
        $this->assertLessThan($baseStrength['control'], $wingStrength['control']);
        $this->assertGreaterThan($baseStrength['defense'], $heavyStrength['defense']);

        $balanced->advance(1);
        $aggressive = $this->simulationWithTactics('factors-balanced', 'total_attack', 'balanced', 'very_heavy');
        $aggressive->advance(1);
        $this->assertLessThan($this->activeEnergy($balanced, 'home'), $this->activeEnergy($aggressive, 'home'));

        $lightDiscipline = ['fouls' => 0, 'cards' => 0];
        $heavyDiscipline = ['fouls' => 0, 'cards' => 0];
        for ($index = 0; $index < 40; $index++) {
            $lightResult = $this->finish($this->simulationWithTactics('discipline-'.$index, 'balanced', 'balanced', 'light'));
            $heavyResult = $this->finish($this->simulationWithTactics('discipline-'.$index, 'balanced', 'balanced', 'very_heavy'));
            $lightDiscipline['fouls'] += $lightResult['home']['stats']['fouls'];
            $heavyDiscipline['fouls'] += $heavyResult['home']['stats']['fouls'];
            $lightDiscipline['cards'] += $lightResult['home']['stats']['yellow_cards'] + $lightResult['home']['stats']['red_cards'];
            $heavyDiscipline['cards'] += $heavyResult['home']['stats']['yellow_cards'] + $heavyResult['home']['stats']['red_cards'];
        }
        $this->assertGreaterThan($lightDiscipline['fouls'], $heavyDiscipline['fouls']);
        $this->assertGreaterThan($lightDiscipline['cards'], $heavyDiscipline['cards']);
    }

    public function test_invalid_tactical_command_is_atomic(): void
    {
        $simulation = $this->simulation('invalid-tactics');

        $this->assertRejectedWithoutMutation($simulation, 'tactics', [
            'formation' => '4-3-3',
            'style' => 'total_attack',
            'attack_direction' => 'invalid',
            'marking' => 'very_heavy',
        ]);
    }

    private function source(string $seed): array
    {
        $source = MatchFixture::make($seed);
        $source['engine_version'] = Simulation::VERSION;
        $source['rules'] = ['max_substitutions' => 3, 'max_substitution_windows' => 3];
        $source['home']['controller'] = 'human';
        $source['away']['controller'] = 'human';

        return $source;
    }

    private function simulation(string $seed): Simulation
    {
        return new Simulation((new MatchInput($this->source($seed)))->data);
    }

    private function simulationWithTactics(string $seed, string $style, string $direction, string $marking): Simulation
    {
        $simulation = $this->simulation($seed);
        $simulation->applyDecision('home', 'tactics', [
            'formation' => '4-4-2',
            'style' => $style,
            'attack_direction' => $direction,
            'marking' => $marking,
        ]);

        return $simulation;
    }

    private function finish(Simulation $simulation): array
    {
        while (! in_array($simulation->state()['status'], ['finished', 'abandoned'], true)) {
            $simulation->advance(120, true);
        }

        return $simulation->result();
    }

    private function activeEnergy(Simulation $simulation, string $side): int
    {
        $state = $simulation->state();

        return array_sum(array_map(
            fn (string $id): int => $state['teams'][$side]['players'][$id]['energy'],
            $state['teams'][$side]['active'],
        ));
    }

    private function internal(Simulation $simulation, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($simulation, $method);

        return $reflection->invoke($simulation, ...$arguments);
    }

    private function assertRejectedWithoutMutation(Simulation $simulation, string $type, array $payload): void
    {
        $before = $simulation->state();

        try {
            $simulation->applyDecision('home', $type, $payload);
            $this->fail('The invalid decision was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $simulation->state());
        }
    }
}
