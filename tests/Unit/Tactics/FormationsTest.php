<?php

namespace Tests\Unit\Tactics;

use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Tactics\Tactics;
use PHPUnit\Framework\TestCase;
use Tests\Support\MatchFixture;

class FormationsTest extends TestCase
{
    private const EXPECTED = [
        '4-4-2', '4-3-3', '4-2-3-1', '3-5-2', '4-1-4-1', '4-5-1', '4-4-1-1',
        '4-2-2-2', '4-1-2-1-2', '4-3-1-2', '4-3-2-1', '4-2-4', '4-1-3-2',
        '3-4-3', '3-4-2-1', '3-4-1-2', '3-5-1-1', '5-3-2', '5-4-1', '5-2-3', '3-6-1',
    ];

    public function test_catalog_defines_valid_roles_shapes_and_profiles_for_all_formations(): void
    {
        $this->assertSame(self::EXPECTED, array_keys(Tactics::FORMATIONS));
        $this->assertSame(self::EXPECTED, array_keys(Tactics::SHAPES));
        $this->assertSame(self::EXPECTED, array_keys(Tactics::PROFILES));

        foreach (Tactics::FORMATIONS as $formation => $roles) {
            $this->assertCount(11, $roles, $formation);
            $this->assertSame('GK', $roles[0], $formation);
            $this->assertSame(1, count(array_filter($roles, fn (string $role): bool => $role === 'GK')), $formation);
            foreach ($roles as $role) {
                $this->assertContains($role, Tactics::POSITIONS, $formation);
            }
            $slots = Tactics::slots($formation);
            $this->assertCount(11, $slots, $formation);
            foreach ($slots as $slot => $position) {
                $this->assertSame($roles[$slot], $position['role']);
                $this->assertGreaterThanOrEqual(0, $position['x']);
                $this->assertLessThanOrEqual(100, $position['x']);
                $this->assertGreaterThanOrEqual(0, $position['y']);
                $this->assertLessThanOrEqual(100, $position['y']);
            }
            foreach (['attack', 'defense', 'control'] as $dimension) {
                $this->assertGreaterThanOrEqual(0.85, Tactics::PROFILES[$formation][$dimension]);
                $this->assertLessThanOrEqual(1.15, Tactics::PROFILES[$formation][$dimension]);
            }
        }
    }

    public function test_engine_v3_simulates_every_formation_deterministically_with_valid_substitutions(): void
    {
        $engine = new FootballMatchEngine;
        foreach (Tactics::FORMATIONS as $formation => $roles) {
            $input = MatchFixture::make('formation-'.str_replace('-', '_', $formation));
            $input['engine_version'] = '3.0.0';
            $input['home']['tactics']['formation'] = $formation;
            $input['away']['tactics']['formation'] = $formation;
            $result = $engine->simulate($input);

            $this->assertSame($result, $engine->simulate($input), $formation);
            $this->assertSame('3.0.0', $result['engine_version']);
            $this->assertSame($formation, $result['home']['final_tactics']['formation']);
            $this->assertSame($formation, $result['away']['final_tactics']['formation']);
            foreach (array_filter($result['events'], fn (array $event): bool => $event['type'] === 'substitution') as $event) {
                $this->assertContains($event['data']['role'], $roles, $formation);
            }
        }
    }

    public function test_formation_profiles_have_observable_effects_without_changing_published_v2_replays(): void
    {
        $engine = new FootballMatchEngine;
        $defensive = MatchFixture::make('formation-profile');
        $defensive['engine_version'] = '3.0.0';
        $defensive['home']['tactics']['formation'] = '5-4-1';
        $attacking = $defensive;
        $attacking['home']['tactics']['formation'] = '4-2-4';

        $this->assertNotSame($engine->simulate($defensive), $engine->simulate($attacking));

        $published = MatchFixture::make('ai-golden', 90, 40);
        $published['engine_version'] = '2.0.0';
        $published['home']['controller'] = $published['away']['controller'] = 'ai';
        $this->assertSame(
            '854ff19451ade1522dd70922a585f4570aa125706b667f5c28b15d85d5698304',
            \App\Domain\Football\Engine\ReplayHash::of($engine->simulate($published)),
        );
    }
}
