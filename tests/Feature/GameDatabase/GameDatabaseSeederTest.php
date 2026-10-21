<?php

namespace Tests\Feature\GameDatabase;

use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Domain\GameDatabase\Models\CompetitionRuleset;
use App\Domain\GameDatabase\Models\CompetitionTemplate;
use App\Domain\GameDatabase\Models\GameDatabaseVersion;
use App\Domain\GameDatabase\Models\PlayerTemplate;
use App\Domain\GameDatabase\Models\StadiumTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class GameDatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_builds_a_complete_consistent_universe(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('game_database_versions', 1);
        $this->assertDatabaseCount('competition_rulesets', 1);
        $this->assertDatabaseCount('competition_templates', 1);
        $this->assertDatabaseCount('club_templates', 10);
        $this->assertDatabaseCount('player_templates', 200);
        $this->assertDatabaseCount('stadium_templates', 10);
        $this->assertDatabaseCount('users', 0);

        $version = GameDatabaseVersion::firstOrFail();
        $this->assertSame('1.0.0', $version->version);
        $data = require database_path('seeders/data/v1.php');
        $this->assertSame(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), $version->checksum);
        $this->assertCount(10, $version->clubs);
        $this->assertCount(200, $version->players);
        $this->assertCount(10, $version->stadiums);
        $this->assertCount(1, $version->competitions);
        $this->assertCount(1, $version->rulesets);

        $competition = $version->competitions->sole();
        $ruleset = $competition->ruleset;
        $this->assertSame(10, $ruleset->club_count);
        $this->assertSame(2, $ruleset->legs);
        $this->assertSame(18, $ruleset->round_count);
        $this->assertSame(90, $ruleset->match_count);
        $this->assertSame(3, $ruleset->points_for_win);
        $this->assertSame(1, $ruleset->points_for_draw);
        $this->assertSame(0, $ruleset->points_for_loss);
        $this->assertSame(['wins', 'goal_difference', 'goals_for', 'club_code'], $ruleset->tiebreakers);
        $this->assertCount(10, $competition->clubs);
        $this->assertTrue($ruleset->competitions->sole()->is($competition));

        foreach ($competition->clubs as $club) {
            $this->assertTrue($club->version->is($version));
            $this->assertTrue($club->competition->is($competition));
            $this->assertGreaterThan(0, $club->initial_balance_cents);
            $this->assertGreaterThan(0, $club->default_ticket_price_cents);
            $this->assertGreaterThan(0, $club->potential_supporters);
            $this->assertCount(20, $club->players);
            $this->assertSame(2, $club->players->where('primary_position', 'GK')->count());
            $this->assertEqualsCanonicalizing(PlayerTemplate::POSITIONS, $club->players->pluck('primary_position')->unique()->all());
            $this->assertNotNull($club->stadium);
            $this->assertTrue($club->stadium->version->is($version));
            $this->assertTrue($club->stadium->club->is($club));
            $this->assertSame($club->stadium->capacity, $club->stadium->available_capacity);
            $this->assertGreaterThan(0, $club->stadium->fixed_operating_cost_cents);
            $this->assertGreaterThan(0, $club->stadium->variable_cost_per_supporter_cents);

            foreach ($club->players as $player) {
                $this->assertTrue($player->version->is($version));
                $this->assertTrue($player->club->is($club));
                $this->assertGreaterThanOrEqual(18, $player->age);
                $this->assertLessThanOrEqual(35, $player->age);
                $this->assertNotContains($player->primary_position, $player->secondary_positions);
                foreach ([...PlayerTemplate::ATTRIBUTES, 'initial_fitness', 'initial_morale'] as $attribute) {
                    $this->assertGreaterThanOrEqual(0, $player->$attribute);
                    $this->assertLessThanOrEqual(100, $player->$attribute);
                }
            }
        }
    }

    public function test_reseeding_preserves_ids_timestamps_and_every_value(): void
    {
        $this->seed();
        $before = $this->snapshot();
        $this->travel(1)->days();
        $this->seed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_published_dataset_cannot_silently_change(): void
    {
        GameDatabaseVersion::create(['version' => '1.0.0', 'name' => 'Existing release', 'checksum' => str_repeat('0', 64)]);

        try {
            $this->seed();
            $this->fail('A changed release must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('nova versão', $exception->getMessage());
        }

        $this->assertDatabaseCount('club_templates', 0);
        $this->assertDatabaseCount('competition_rulesets', 0);
    }

    public function test_failed_seed_rolls_back_the_entire_universe(): void
    {
        StadiumTemplate::creating(function (): void {
            throw new RuntimeException('Interrupted seed');
        });

        try {
            $this->seed();
            $this->fail('The injected failure must abort the seed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted seed', $exception->getMessage());
        } finally {
            StadiumTemplate::flushEventListeners();
            // Restore validation hooks after removing the test-only listener.
            StadiumTemplate::clearBootedModels();
        }

        foreach ($this->tables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->seed();
        $this->assertDatabaseCount('player_templates', 200);
    }

    public function test_references_cannot_cross_data_versions(): void
    {
        $this->seed();
        $version = GameDatabaseVersion::create(['version' => '2.0.0', 'name' => 'Other universe', 'checksum' => str_repeat('a', 64)]);
        foreach ([CompetitionTemplate::class, ClubTemplate::class, StadiumTemplate::class, PlayerTemplate::class] as $model) {
            $record = $model::firstOrFail();
            try {
                $record->update(['game_database_version_id' => $version->id]);
                $this->fail("A cross-version reference was accepted by $model.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_database_rejects_a_duplicate_player_code_in_a_release(): void
    {
        $this->seed();
        $this->expectException(QueryException::class);
        PlayerTemplate::firstOrFail()->replicate()->save();
    }

    public function test_database_rejects_a_second_stadium_for_the_same_club(): void
    {
        $this->seed();
        $this->expectException(QueryException::class);
        StadiumTemplate::firstOrFail()->replicate()->save();
    }

    public function test_database_prevents_deleting_a_club_with_players_and_a_stadium(): void
    {
        $this->seed();
        $this->expectException(QueryException::class);
        ClubTemplate::firstOrFail()->delete();
    }

    public function test_players_reject_invalid_ratings_and_accept_both_boundaries(): void
    {
        $this->seed();
        $player = PlayerTemplate::firstOrFail();
        foreach ([...PlayerTemplate::ATTRIBUTES, 'initial_fitness', 'initial_morale'] as $attribute) {
            foreach ([-1, 101] as $invalid) {
                try {
                    $player->update([$attribute => $invalid]);
                    $this->fail("Invalid $attribute was accepted.");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey($attribute, $exception->errors());
                } finally {
                    $player->refresh();
                }
            }
            foreach ([0, 100] as $valid) {
                $player->update([$attribute => $valid]);
                $this->assertSame($valid, $player->fresh()->$attribute);
            }
        }
    }

    public function test_player_rejects_unknown_or_duplicate_positions(): void
    {
        $this->seed();
        foreach ([['primary_position' => 'XYZ'], ['secondary_positions' => ['XYZ']], ['secondary_positions' => ['CB', 'CB']], ['secondary_positions' => ['GK']]] as $invalid) {
            try {
                PlayerTemplate::firstOrFail()->update($invalid);
                $this->fail('Invalid position was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_stadium_rejects_unavailable_capacity_and_negative_costs(): void
    {
        $this->seed();
        foreach ([['available_capacity' => 1000000], ['available_capacity' => -1], ['fixed_operating_cost_cents' => -1], ['variable_cost_per_supporter_cents' => -1]] as $invalid) {
            try {
                StadiumTemplate::firstOrFail()->update($invalid);
                $this->fail('Invalid stadium settings were accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($invalid), $exception->errors());
            }
        }
    }

    public function test_ruleset_rejects_inconsistent_round_and_match_counts(): void
    {
        $this->seed();
        foreach ([['round_count' => 17], ['match_count' => 89], ['club_count' => 9]] as $invalid) {
            try {
                CompetitionRuleset::firstOrFail()->update($invalid);
                $this->fail('An inconsistent league definition was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_club_rejects_negative_starting_finances(): void
    {
        $this->seed();
        foreach (['initial_balance_cents', 'default_ticket_price_cents'] as $attribute) {
            try {
                ClubTemplate::firstOrFail()->update([$attribute => -1]);
                $this->fail('Negative starting finances were accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($attribute, $exception->errors());
            }
        }
    }

    public function test_financial_amounts_must_be_whole_centavos(): void
    {
        $this->seed();
        $this->expectException(ValidationException::class);
        ClubTemplate::firstOrFail()->update(['initial_balance_cents' => 12.5]);
    }

    private function tables(): array
    {
        return ['game_database_versions', 'competition_rulesets', 'competition_templates', 'club_templates', 'stadium_templates', 'player_templates'];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->tables() as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
