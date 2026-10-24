<?php

namespace Tests\Unit\AI;

use App\Domain\AI\LineupPlanner;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\MatchInput;
use App\Domain\Football\Engine\V4\Simulation;
use App\Domain\Tactics\Tactics;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class InteractiveClubAiTest extends TestCase
{
    public function test_ai_reacts_deterministically_when_winning_losing_and_drawing(): void
    {
        $scenarios = [
            'losing' => [-2, '4-2-4', 'total_attack', 'attacking'],
            'winning' => [2, '5-4-1', 'counter', 'defensive'],
            'drawing' => [0, '4-4-2', 'balanced', 'balanced'],
        ];

        foreach ($scenarios as $name => [$difference, $formation, $style, $mentality]) {
            $first = $this->advanceContext("context-{$name}", $difference);
            $second = $this->advanceContext("context-{$name}", $difference);
            $team = $first['teams']['home'];
            $tacticalDecisions = array_values(array_filter(
                $first['decisions'],
                fn (array $decision): bool => $decision['side'] === 'home' && $decision['actor'] === 'ai' && $decision['type'] === 'tactics',
            ));

            $this->assertSame($first, $second);
            $this->assertSame($formation, $team['tactics']['formation']);
            $this->assertSame($style, $team['tactics']['style']);
            $this->assertSame($mentality, $team['tactics']['mentality']);
            $this->assertArrayHasKey($team['tactics']['formation'], Tactics::FORMATIONS);
            $this->assertCount($difference === 0 ? 0 : 1, $tacticalDecisions);
            $this->assertCount(count($team['active']), array_unique($team['active']));
        }
    }

    public function test_tired_or_injured_players_trigger_valid_deterministic_ai_changes(): void
    {
        $input = $this->input('tired-and-injured', 'ai', 'human');
        $initial = new Simulation($input);
        $state = $initial->state();
        $state['minute'] = 59;
        $injured = $state['teams']['home']['active'][2];
        foreach ($state['teams']['home']['active'] as $id) {
            $state['teams']['home']['players'][$id]['energy'] = 3000;
        }
        $state['teams']['home']['players'][$injured]['energy'] = 0;
        $state['teams']['home']['players'][$injured]['injured'] = true;
        $state['teams']['home']['players'][$injured]['injury_days'] = 10;

        $first = new Simulation($input, $state);
        $second = new Simulation($input, $state);
        $firstState = $first->advance(1);
        $secondState = $second->advance(1);
        $team = $firstState['teams']['home'];

        $this->assertSame($firstState, $secondState);
        $this->assertSame('low', $team['tactics']['pressure']);
        $this->assertSame('slow', $team['tactics']['tempo']);
        $this->assertNotContains($injured, $team['active']);
        $this->assertSame(1, $team['stats']['substitutions']);
        $this->assertSame(1, $team['substitution_windows']);
        $this->assertLessThanOrEqual($input['rules']['max_substitutions'], $team['stats']['substitutions']);
        $this->assertCount(count($team['active']), array_unique($team['active']));
        $this->assertEmpty(array_intersect($team['active'], $team['bench']));

        $substitution = collect($firstState['events'])->first(
            fn (array $event): bool => $event['type'] === 'substitution' && $event['side'] === 'home',
        );
        $this->assertNotNull($substitution);
        $this->assertSame($injured, $substitution['data']['out_player_id']);
        $this->assertSame('injury', $substitution['data']['reason']);
    }

    public function test_lineup_planner_excludes_unavailable_and_exhausted_players_deterministically(): void
    {
        $fixture = MatchFixture::make('unavailable-ai');
        $roster = [...$fixture['home']['starters'], ...$fixture['home']['substitutes']];
        $unavailable = $roster[2]['id'];
        $exhausted = $roster[3]['id'];
        $roster[2]['available'] = false;
        $roster[3]['fitness'] = 0;
        foreach ([2, 3] as $index) {
            $replacement = $roster[$index];
            $replacement['id'] = 'replacement-'.$index;
            $replacement['available'] = true;
            $replacement['fitness'] = 100;
            $roster[] = $replacement;
        }

        $planner = new LineupPlanner;
        $first = $planner->plan($roster, $roster, 7, true);
        $second = $planner->plan(array_reverse($roster), array_reverse($roster), 7, true);
        $selected = array_column([...$first['starters'], ...$first['substitutes']], 'id');

        $this->assertSame($first, $second);
        $this->assertNotContains($unavailable, $selected);
        $this->assertNotContains($exhausted, $selected);
        $this->assertCount(count($selected), array_unique($selected));
        $this->assertCount(11, $first['starters']);
        $this->assertLessThanOrEqual(7, count($first['substitutes']));
        $this->assertArrayHasKey($first['tactics']['formation'], Tactics::FORMATIONS);
    }

    public function test_ai_never_overwrites_the_human_coachs_decisions(): void
    {
        $input = $this->input('preserve-human', 'human', 'ai');
        $simulation = new Simulation($input);
        $simulation->applyDecision('home', 'tactics', [
            'formation' => '3-4-3',
            'style' => 'total_attack',
            'attack_direction' => 'wings',
            'marking' => 'heavy',
        ]);
        $state = $simulation->state();
        $state['minute'] = 69;
        $state['teams']['home']['stats']['goals'] = 0;
        $state['teams']['away']['stats']['goals'] = 2;
        $simulation = new Simulation($input, $state);
        $after = $simulation->advance(1);

        $this->assertSame('3-4-3', $after['teams']['home']['tactics']['formation']);
        $this->assertSame('total_attack', $after['teams']['home']['tactics']['style']);
        $this->assertSame('wings', $after['teams']['home']['tactics']['attack_direction']);
        $this->assertSame('heavy', $after['teams']['home']['tactics']['marking']);
        $this->assertCount(1, array_filter(
            $after['decisions'],
            fn (array $decision): bool => $decision['side'] === 'home' && $decision['actor'] === 'human',
        ));
        $this->assertCount(0, array_filter(
            $after['decisions'],
            fn (array $decision): bool => $decision['side'] === 'home' && $decision['actor'] === 'ai',
        ));
    }

    public function test_two_ai_clubs_finish_quickly_with_the_same_engine_and_without_duplicate_events(): void
    {
        $input = $this->input('ai-versus-ai-fast', 'ai', 'ai');
        $fast = new Simulation($input);
        $fast->advance(120, true);
        $fastState = $fast->state();
        $fastResult = $fast->result();
        $engineResult = (new FootballMatchEngine)->simulate($input);

        $this->assertSame($engineResult, $fastResult);
        $this->assertSame($engineResult, (new FootballMatchEngine)->simulate($input));
        $this->assertSame('finished', $fastResult['status']);
        $this->assertSame(90 + $fastResult['added_time'], $fastResult['minutes']);
        $this->assertSame(range(1, count($fastResult['events'])), array_column($fastResult['events'], 'sequence'));
        $this->assertCount(1, array_filter($fastResult['events'], fn (array $event): bool => $event['type'] === 'kickoff'));
        $this->assertCount(1, array_filter($fastResult['events'], fn (array $event): bool => $event['type'] === 'half_time'));
        $this->assertCount(1, array_filter($fastResult['events'], fn (array $event): bool => $event['type'] === 'full_time'));

        foreach (['home', 'away'] as $side) {
            $this->assertSame('ai', $fastResult[$side]['controller']);
            $this->assertLessThanOrEqual($input['rules']['max_substitutions'], $fastResult[$side]['stats']['substitutions']);
            $this->assertLessThanOrEqual($input['rules']['max_substitution_windows'], $fastState['teams'][$side]['substitution_windows']);
            $this->assertSame(
                $fastResult[$side]['stats']['substitutions'],
                count(array_filter($fastResult['events'], fn (array $event): bool => $event['type'] === 'substitution' && $event['side'] === $side)),
            );
            $this->assertSame(
                $fastResult[$side]['stats']['goals'],
                count(array_filter($fastResult['events'], fn (array $event): bool => $event['type'] === 'goal' && $event['side'] === $side)),
            );
        }
        foreach ($fastResult['decisions'] as $decision) {
            $this->assertNotSame('human', $decision['actor']);
            if ($decision['actor'] === 'system') {
                $this->assertSame('resume', $decision['type']);
            }
            if ($decision['type'] === 'tactics') {
                $this->assertSame('ai', $decision['actor']);
                $this->assertArrayHasKey($decision['payload']['formation'], Tactics::FORMATIONS);
            }
        }
    }

    private function advanceContext(string $seed, int $goalDifference): array
    {
        $input = $this->input($seed, 'ai', 'human');
        $simulation = new Simulation($input);
        $state = $simulation->state();
        $state['minute'] = 69;
        $state['teams']['home']['stats']['goals'] = max(0, $goalDifference);
        $state['teams']['away']['stats']['goals'] = max(0, -$goalDifference);

        return (new Simulation($input, $state))->advance(1);
    }

    private function input(string $seed, string $homeController, string $awayController): array
    {
        $source = MatchFixture::make($seed);
        $source['engine_version'] = Simulation::VERSION;
        $source['rules'] = ['max_substitutions' => 3, 'max_substitution_windows' => 3];
        $source['home']['controller'] = $homeController;
        $source['away']['controller'] = $awayController;

        return (new MatchInput($source))->data;
    }
}
