<?php

namespace Tests\Feature\Matches;

use App\Application\Careers\CreateCareer;
use App\Application\Matches\SimulateFriendly;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Models\MatchSimulation;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class FriendlyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->career = $this->createCareer();
        $this->actingAs($this->owner);
    }

    private function createCareer(): Career
    {
        return app(CreateCareer::class)->execute($this->owner, ['name' => 'Amistosos', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
    }

    private function saveLineup(): void
    {
        app(SaveSquad::class)->execute($this->owner, $this->career, app(SquadSheet::class)->forCareer($this->career)['settings']);
    }

    private function payload(): array
    {
        return ['seed' => 'friendly-seed', 'request_id' => (string) Str::uuid(),
            'away_club_id' => $this->career->clubs()->where('id', '!=', $this->career->controlledClub->career_club_id)->firstOrFail()->id];
    }

    private function simulate(array $payload): MatchSimulation
    {
        return app(SimulateFriendly::class)->execute($this->owner, $this->career, $payload);
    }

    private function gameState(): array
    {
        $data = [];
        foreach (['careers', 'career_clubs', 'career_players', 'career_stadiums', 'career_lineups', 'career_lineup_players', 'competition_editions', 'matches'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $data;
    }

    public function test_friendly_persists_immutable_snapshots_and_replays_after_database_roundtrip(): void
    {
        $this->saveLineup();
        $before = $this->gameState();
        $payload = $this->payload();
        $this->get(route('careers.friendlies.create', $this->career))->assertOk()->assertSee('Jogar amistoso');
        $this->post(route('careers.friendlies.store', $this->career), $payload)->assertRedirect();
        $simulation = MatchSimulation::sole();
        $this->assertSame($before, $this->gameState());
        $this->assertSame(ReplayHash::of($simulation->input), $simulation->input_checksum);
        $this->assertSame(ReplayHash::of($simulation->result), $simulation->result_checksum);
        $this->assertSame($simulation->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($simulation->input)));
        $this->assertSame('friendly-seed', $simulation->seed);
        $this->assertNull($simulation->result['attendance']['present']);
        $this->assertCount(11, $simulation->input['home']['starters']);
        $this->assertCount(7, $simulation->input['home']['substitutes']);
    }

    public function test_result_page_and_history_render_frozen_statistics_and_events(): void
    {
        $this->saveLineup();
        $simulation = $this->simulate($this->payload());
        $this->get(route('careers.friendlies.show', [$this->career, $simulation]))->assertOk()->assertSee('Estatísticas das equipes')->assertSee('Todas as jogadas')->assertSee('Condição final simulada');
        $this->get(route('careers.friendlies.create', $this->career))->assertOk()->assertSee($simulation->result['away']['name']);
        $this->career->controlledClub->club->update(['name' => 'Edited club']);
        $this->career->players()->first()->update(['passing' => 1]);
        $simulation->refresh();
        $this->assertSame($simulation->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($simulation->input)));
    }

    public function test_duplicate_request_returns_one_simulation_and_conflicting_payload_is_rejected(): void
    {
        $this->saveLineup();
        $payload = $this->payload();
        $first = $this->simulate($payload);
        $this->assertTrue($first->is($this->simulate($payload)));
        $this->assertDatabaseCount('match_simulations', 1);
        $payload['seed'] = 'changed';
        $this->post(route('careers.friendlies.store', $this->career), $payload)->assertSessionHasErrors('request_id');
    }

    public function test_only_saved_available_squads_can_play(): void
    {
        $this->post(route('careers.friendlies.store', $this->career), $this->payload())->assertSessionHasErrors('lineup');
        $this->saveLineup();
        $data = app(SquadSheet::class)->forCareer($this->career)['settings'];
        $this->career->players()->findOrFail($data['starters'][1])->update(['suspended_matches' => 1]);
        $this->post(route('careers.friendlies.store', $this->career), $this->payload())->assertSessionHasErrors('lineup');
        $this->assertDatabaseCount('match_simulations', 0);
    }

    public function test_foreign_opponents_and_forged_inputs_are_not_used(): void
    {
        $this->saveLineup();
        $other = $this->createCareer();
        foreach ([$this->career->controlledClub->career_club_id, $other->clubs()->first()->id, 999999] as $id) {
            $payload = $this->payload();
            $payload['away_club_id'] = $id;
            $this->post(route('careers.friendlies.store', $this->career), $payload)->assertSessionHasErrors('away_club_id');
        }
        $payload = $this->payload() + ['home_context' => ['attendance' => 999999], 'result' => ['home_goals' => 999], 'user_id' => 999];
        $simulation = $this->simulate($payload);
        $this->assertNull($simulation->result['attendance']['present']);
        $this->assertLessThan(999, $simulation->result['home']['stats']['goals']);
    }

    public function test_other_users_and_guests_cannot_simulate_or_read_results(): void
    {
        $this->saveLineup();
        $simulation = $this->simulate($this->payload());
        $this->actingAs(User::factory()->create());
        $this->get(route('careers.friendlies.create', $this->career))->assertForbidden();
        $this->post(route('careers.friendlies.store', $this->career), $this->payload())->assertForbidden();
        $this->get(route('careers.friendlies.show', [$this->career, $simulation]))->assertForbidden();
        $this->post('/logout');
        $this->get(route('careers.friendlies.show', [$this->career, $simulation]))->assertRedirect(route('login'));
    }

    public function test_results_cannot_be_accessed_through_another_career_url(): void
    {
        $this->saveLineup();
        $simulation = $this->simulate($this->payload());
        $other = $this->createCareer();
        $this->get(route('careers.friendlies.show', [$other, $simulation]))->assertNotFound();
    }

    public function test_failed_persistence_does_not_change_the_career_or_leave_partial_results(): void
    {
        $this->saveLineup();
        $before = $this->gameState();
        MatchSimulation::creating(fn () => throw new RuntimeException('Interrupted persistence'));
        try {
            $this->simulate($this->payload());
            $this->fail('The injected failure must abort.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted persistence', $exception->getMessage());
        } finally {
            MatchSimulation::flushEventListeners();
            MatchSimulation::clearBootedModels();
        }
        $this->assertSame($before, $this->gameState());
        $this->assertDatabaseCount('match_simulations', 0);
    }

    public function test_processing_state_blocks_new_simulations(): void
    {
        $this->saveLineup();
        $this->career->update(['state' => CareerState::Processing]);
        $this->post(route('careers.friendlies.store', $this->career), $this->payload())->assertSessionHasErrors('match');
        $this->assertDatabaseCount('match_simulations', 0);
    }

    public function test_persisted_snapshots_cannot_be_edited_by_the_model(): void
    {
        $this->saveLineup();
        $simulation = $this->simulate($this->payload());
        $this->expectException(LogicException::class);
        $simulation->update(['seed' => 'rewritten']);
    }
}
