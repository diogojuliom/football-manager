<?php

namespace Tests\Unit\AI;

use App\Domain\AI\AdministrationPlanner;
use App\Domain\AI\LineupPlanner;
use App\Domain\AI\V1\MatchDecisions;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Tactics\Tactics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class ClubAiTest extends TestCase
{
    private function roster(): array
    {
        $fixture = MatchFixture::make();

        return [...$fixture['home']['starters'], ...$fixture['home']['substitutes']];
    }

    public function test_planner_can_build_each_supported_formation_with_optimal_role_coverage(): void
    {
        $fixture = MatchFixture::make();
        foreach (Tactics::FORMATIONS as $formation => $roles) {
            $roster = $fixture['home']['starters'];
            foreach ($roles as $slot => $role) {
                $roster[$slot]['primary_position'] = $role;
            }
            $plan = (new LineupPlanner)->plan($roster, $this->roster(), 0, false, $formation);
            $this->assertSame($formation, $plan['tactics']['formation']);
            $this->assertCount(11, $plan['starters']);
            foreach ($roles as $slot => $role) {
                $this->assertSame($role, $plan['starters'][$slot]['primary_position']);
            }
        }
    }

    public function test_planner_adapts_the_formation_to_strength_and_home_context(): void
    {
        $planner = new LineupPlanner;
        $scenarios = [
            [90, 40, true, '4-2-4'],
            [40, 90, false, '5-4-1'],
            [65, 65, true, '3-5-2'],
            [65, 65, false, '4-1-4-1'],
        ];

        foreach ($scenarios as [$ownStrength, $opponentStrength, $home, $expected]) {
            $fixture = MatchFixture::make('formation-context-'.$ownStrength.'-'.$opponentStrength.'-'.(int) $home, $ownStrength, $opponentStrength);
            $roster = [...$fixture['home']['starters'], ...$fixture['home']['substitutes']];
            $opponent = [...$fixture['away']['starters'], ...$fixture['away']['substitutes']];
            $this->assertSame($expected, $planner->plan($roster, $opponent, 7, $home)['tactics']['formation']);
        }
    }

    public function test_selection_is_deterministic_and_excludes_unavailable_players(): void
    {
        $roster = $this->roster();
        $roster[2]['available'] = false;
        $roster[3]['fitness'] = 0;
        $extra = $roster[2];
        $extra['id'] = 'healthy-extra';
        $extra['available'] = true;
        $extra['primary_position'] = 'CB';
        $roster[] = $extra;
        $planner = new LineupPlanner;
        $plan = $planner->plan($roster, $roster, 3);
        $this->assertSame($plan, $planner->plan(array_reverse($roster), array_reverse($roster), 3));
        $ids = array_column([...$plan['starters'], ...$plan['substitutes']], 'id');
        $this->assertCount(count($ids), array_unique($ids));
        $this->assertNotContains($roster[2]['id'], $ids);
        $this->assertNotContains($roster[3]['id'], $ids);
        $this->assertCount(3, $plan['substitutes']);
        $this->assertSame('GK', $plan['starters'][0]['primary_position']);
        $this->assertSame('GK', $plan['substitutes'][0]['primary_position']);
    }

    public function test_fitness_morale_and_quality_affect_selection(): void
    {
        $roster = $this->roster();
        $tired = $roster[2];
        $tired['id'] = 'tired-star';
        $tired['fitness'] = 1;
        foreach (array_keys(Tactics::ATTRIBUTES) as $attribute) {
            $tired[$attribute] = 100;
        }
        $roster[] = $tired;
        $plan = (new LineupPlanner)->plan($roster, $roster);
        $this->assertNotContains('tired-star', array_column($plan['starters'], 'id'));
        $healthy = $tired;
        $healthy['id'] = 'healthy-star';
        $healthy['fitness'] = 100;
        $healthy['morale'] = 100;
        $roster[] = $healthy;
        $plan = (new LineupPlanner)->plan($roster, $roster);
        $this->assertContains('healthy-star', array_column($plan['starters'], 'id'));
    }

    public function test_insufficient_roster_fails_without_inventing_players(): void
    {
        $roster = array_filter($this->roster(), fn ($p) => $p['primary_position'] !== 'GK');
        $this->expectException(InvalidArgumentException::class);
        (new LineupPlanner)->plan(array_values($roster), $this->roster());
    }

    public function test_initial_strategy_and_reactions_follow_match_context(): void
    {
        $this->assertSame('defensive', MatchDecisions::initial(40, 80, 90, false)['mentality']);
        $this->assertSame('attacking', MatchDecisions::initial(90, 40, 90, true)['mentality']);
        $this->assertSame('low', MatchDecisions::initial(90, 40, 40, true)['pressure']);
        $tactics = ['formation' => '4-4-2', 'mentality' => 'balanced', 'pressure' => 'normal', 'tempo' => 'normal', 'build_up' => 'balanced'];
        foreach ([[65, -1, 11, 11, 'chasing_goal', 'attacking'], [75, 1, 11, 11, 'protecting_lead', 'defensive'], [35, 0, 10, 11, 'numerical_disadvantage', 'defensive'], [35, 0, 11, 10, 'numerical_advantage', 'attacking']] as [$minute, $score, $own, $other, $reason, $mentality]) {
            $decision = MatchDecisions::react($tactics, $minute, $score, $own, $other, 80);
            $this->assertSame($reason, $decision['reason']);
            $this->assertSame($mentality, $decision['tactics']['mentality']);
            $this->assertSame('4-4-2', $decision['tactics']['formation']);
        }
        $this->assertSame('low', MatchDecisions::react($tactics, 80, -1, 11, 11, 30)['tactics']['pressure']);
    }

    public function test_substitution_respects_cooldown_available_slots_and_goalkeeper_role(): void
    {
        $fixture = MatchFixture::make();
        $team = $fixture['home'];
        $players = [];
        foreach ([...$team['starters'], ...$team['substitutes']] as $player) {
            $players[$player['id']] = $player + ['energy' => 10000, 'stats' => ['yellow_cards' => 0]];
        }
        $active = array_column($team['starters'], 'id');
        $bench = array_column($team['substitutes'], 'id');
        $players[$active[2]]['energy'] = 8000;
        $players[$active[2]]['stats']['yellow_cards'] = 1;
        $decision = MatchDecisions::substitute(46, -100, 0, $players, $active, $bench, Tactics::FORMATIONS['4-4-2']);
        $this->assertSame(2, $decision['slot']);
        $this->assertSame('booking', $decision['reason']);
        $this->assertContains($decision['in'], $bench);
        $this->assertNull(MatchDecisions::substitute(50, 46, 0, $players, $active, $bench, Tactics::FORMATIONS['4-4-2']));
        unset($active[2]);
        $decision = MatchDecisions::substitute(70, -100, -1, $players, $active, $bench, Tactics::FORMATIONS['4-4-2']);
        $this->assertNotSame(2, $decision['slot']);
        $players[$active[0]]['energy'] = 1000;
        $decision = MatchDecisions::substitute(20, -100, 0, $players, $active, [$bench[0]], Tactics::FORMATIONS['4-4-2']);
        $this->assertSame(0, $decision['slot']);
        $this->assertSame('GK', $players[$decision['in']]['primary_position']);
    }

    public function test_ai_teams_play_complete_reproducible_games_without_reusing_sent_off_or_substituted_players(): void
    {
        $golden = MatchFixture::make('ai-golden', 90, 40);
        $golden['engine_version'] = '2.0.0';
        $golden['home']['controller'] = $golden['away']['controller'] = 'ai';
        $this->assertSame('854ff19451ade1522dd70922a585f4570aa125706b667f5c28b15d85d5698304', ReplayHash::of((new FootballMatchEngine)->simulate($golden)));

        $reasons = [];
        for ($seed = 0; $seed < 60; $seed++) {
            $input = MatchFixture::make('ai-'.$seed, $seed % 2 === 0 ? 90 : 40, $seed % 2 === 0 ? 40 : 90);
            $input['engine_version'] = '2.0.0';
            foreach (['home', 'away'] as $side) {
                $input[$side]['controller'] = 'ai';
                $input[$side]['ai_policy_version'] = '1.0.0';
            }
            $result = (new FootballMatchEngine)->simulate($input);
            $this->assertSame(ReplayHash::of($result), ReplayHash::of((new FootballMatchEngine)->simulate($input)));
            $this->assertSame(90, $result['minutes']);
            foreach (['home', 'away'] as $side) {
                $active = array_fill_keys(array_column($input[$side]['starters'], 'id'), true);
                $this->assertSame('ai', $result[$side]['controller']);
                $this->assertLessThanOrEqual(3, $result[$side]['stats']['substitutions']);
                $this->assertSame($result[$side]['stats']['goals'], count(array_filter($result['events'], fn ($e) => $e['side'] === $side && $e['type'] === 'goal')));
                foreach ($result['events'] as $event) {
                    if ($event['side'] !== $side) {
                        continue;
                    }
                    if ($event['type'] === 'tactical_change') {
                        $reasons[$event['data']['reason']] = true;
                    } elseif ($event['type'] === 'substitution') {
                        $this->assertArrayHasKey($event['data']['out_player_id'], $active);
                        $this->assertArrayNotHasKey($event['player_id'], $active);
                        unset($active[$event['data']['out_player_id']]);
                        $active[$event['player_id']] = true;
                    } elseif ($event['player_id'] !== null) {
                        $this->assertArrayHasKey($event['player_id'], $active);
                        if ($event['type'] === 'red_card') {
                            unset($active[$event['player_id']]);
                        }
                    }
                }
            }
        }
        $this->assertArrayHasKey('chasing_goal', $reasons);
        $this->assertArrayHasKey('protecting_lead', $reasons);
        $this->assertArrayHasKey('numerical_disadvantage', $reasons);
    }

    public function test_human_tactics_do_not_change_automatically_and_old_engine_replay_is_preserved(): void
    {
        $input = MatchFixture::make();
        $legacy = (new FootballMatchEngine)->simulate($input);
        $this->assertSame('26814de60f08eb15e0b44314f48d11d4431911e79432d338e3127a57080d2900', ReplayHash::of($legacy));
        unset($input['engine_version']);
        $result = (new FootballMatchEngine)->simulate($input);
        $this->assertSame(FootballMatchEngine::VERSION, $result['engine_version']);
        $this->assertSame($input['home']['tactics'] + [
            'style' => 'balanced', 'attack_direction' => 'balanced', 'marking' => 'light',
        ], $result['home']['final_tactics']);
        $this->assertCount(0, array_filter($result['events'], fn ($event) => $event['type'] === 'tactical_change'));
        $input['home']['controller'] = 'ai';
        $input['home']['ai_policy_version'] = 'unknown';
        $this->expectException(InvalidArgumentException::class);
        (new FootballMatchEngine)->simulate($input);
    }

    private function finances(): array
    {
        return ['balance_cents' => 40000000, 'ticket_price_cents' => 3000, 'capacity' => 20000, 'fixed_cost_cents' => 2000000,
            'variable_cost_cents' => 500, 'potential_supporters' => 60000, 'loyalty' => 80, 'engagement' => 80, 'infrastructure_level' => 3];
    }

    public function test_administration_recommends_affordable_projects_and_keeps_an_operating_reserve(): void
    {
        $state = $this->finances();
        $before = $state;
        $offers = [
            ['id' => 'expensive', 'type' => 'capacity', 'cost_cents' => 90000000, 'days' => 120],
            ['id' => 'upgrade', 'type' => 'modernization', 'cost_cents' => 5000000, 'days' => 30],
            ['id' => 'expansion', 'type' => 'capacity', 'cost_cents' => 10000000, 'days' => 90],
        ];
        $plan = (new AdministrationPlanner)->plan($state, $offers);
        $this->assertSame($state, $before);
        $this->assertSame('upgrade', $plan['investment']['id']);
        $this->assertLessThanOrEqual($state['balance_cents'] - $plan['operating_reserve_cents'], $plan['investment']['cost_cents']);
        $this->assertLessThanOrEqual($state['capacity'], $plan['estimated_attendance']);
        $this->assertSame($plan['estimated_attendance'] * $plan['ticket_price_cents'], $plan['estimated_revenue_cents']);
        $this->assertSame('recommendation', $plan['kind']);
    }

    public function test_administration_cannot_borrow_money_build_without_demand_or_overlap_projects(): void
    {
        $planner = new AdministrationPlanner;
        $offer = [['id' => 'expansion', 'type' => 'capacity', 'cost_cents' => 1, 'days' => 30]];
        foreach ([['balance_cents' => -1], ['balance_cents' => 0], ['active_project' => true], ['potential_supporters' => 10]] as $override) {
            $plan = $planner->plan(array_replace($this->finances(), $override), $offer);
            $this->assertNull($plan['investment']);
            $this->assertGreaterThanOrEqual(0, $plan['investment_budget_cents']);
        }
        $this->expectException(InvalidArgumentException::class);
        $planner->plan($this->finances(), [['id' => 'free', 'type' => 'capacity', 'cost_cents' => -1, 'days' => 30]]);
    }
}
