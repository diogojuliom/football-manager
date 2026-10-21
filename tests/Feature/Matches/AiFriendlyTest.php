<?php

namespace Tests\Feature\Matches;

use App\Application\Careers\CreateCareer;
use App\Application\Matches\SimulateFriendly;
use App\Domain\Career\Models\Career;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Models\MatchSimulation;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MatchFixture;
use Tests\TestCase;

class AiFriendlyTest extends TestCase
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
        return app(CreateCareer::class)->execute($this->owner, ['name' => 'Clubs AI', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
    }

    private function payload(): array
    {
        $ids = $this->career->clubs()->where('id', '!=', $this->career->controlledClub->career_club_id)->orderBy('id')->pluck('id')->all();

        return ['mode' => 'ai_vs_ai', 'home_club_id' => $ids[0], 'away_club_id' => $ids[1], 'seed' => 'two-ai-clubs', 'request_id' => (string) Str::uuid()];
    }

    private function world(): string
    {
        $state = [];
        foreach (['careers', 'career_clubs', 'career_players', 'career_stadiums', 'career_club_support', 'career_lineups', 'career_lineup_players', 'matches'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return ReplayHash::of($state);
    }

    public function test_two_ai_clubs_can_play_without_any_human_lineup_and_without_changing_world_state(): void
    {
        $before = $this->world();
        $this->get(route('careers.friendlies.create', $this->career))->assertOk()->assertSee('IA contra IA');
        $this->assertDatabaseCount('match_simulations', 0);
        $this->assertDatabaseCount('career_lineups', 0);
        $this->post(route('careers.friendlies.store', $this->career), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $simulation = MatchSimulation::sole();
        $this->assertSame('2.0.0', $simulation->engine_version);
        $this->assertSame('finished', $simulation->result['status']);
        $this->assertSame(90, $simulation->result['minutes']);
        foreach (['home', 'away'] as $side) {
            $this->assertSame('ai', $simulation->input[$side]['controller']);
            $this->assertSame('1.0.0', $simulation->input[$side]['ai_policy_version']);
            $this->assertCount(11, $simulation->input[$side]['starters']);
            $this->assertLessThanOrEqual(7, count($simulation->input[$side]['substitutes']));
            $this->assertSame('recommendation', $simulation->input[$side]['administration_plan']['kind']);
        }
        $this->assertSame($before, $this->world());
        $simulation->refresh();
        $this->assertSame($simulation->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($simulation->input)));
        $this->get(route('careers.friendlies.show', [$this->career, $simulation]))->assertOk()->assertSee('decisões da IA')->assertSee('Recomendações administrativas')->assertSee('Ajuste tático');
    }

    public function test_injured_suspended_and_exhausted_ai_players_are_excluded_from_the_snapshot(): void
    {
        $payload = $this->payload();
        $club = $this->career->clubs()->findOrFail($payload['home_club_id']);
        $players = $club->players()->where('primary_position', '!=', 'GK')->take(3)->get();
        $players[0]->update(['suspended_matches' => 1]);
        $players[1]->update(['fitness' => 0]);
        $players[2]->update(['injured_until' => $this->career->current_date->addDays(20)]);
        $simulation = app(SimulateFriendly::class)->execute($this->owner, $this->career, $payload);
        $ids = array_column([...$simulation->input['home']['starters'], ...$simulation->input['home']['substitutes']], 'id');
        foreach ($players as $player) {
            $this->assertNotContains((string) $player->id, $ids);
        }
    }

    public function test_insufficient_ai_squad_is_rejected_without_invented_players_or_results(): void
    {
        $payload = $this->payload();
        $this->career->clubs()->findOrFail($payload['home_club_id'])->players()->where('primary_position', 'GK')->update(['fitness' => 0]);
        $this->post(route('careers.friendlies.store', $this->career), $payload)->assertSessionHasErrors('lineup');
        $this->assertDatabaseCount('match_simulations', 0);
        $this->assertDatabaseCount('career_players', 200);
    }

    public function test_ai_mode_rejects_human_foreign_and_identical_clubs(): void
    {
        $other = $this->createCareer();
        $base = $this->payload();
        foreach ([['home_club_id' => $this->career->controlledClub->career_club_id], ['home_club_id' => $other->clubs()->first()->id], ['away_club_id' => $other->clubs()->first()->id], ['away_club_id' => $base['home_club_id']]] as $invalid) {
            $this->post(route('careers.friendlies.store', $this->career), array_replace($base, $invalid))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('match_simulations', 0);
    }

    public function test_ai_mode_is_authorized_and_idempotent_and_rejects_changed_payloads(): void
    {
        $payload = $this->payload();
        $first = app(SimulateFriendly::class)->execute($this->owner, $this->career, $payload);
        $second = app(SimulateFriendly::class)->execute($this->owner, $this->career, $payload);
        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('match_simulations', 1);
        $this->post(route('careers.friendlies.store', $this->career), array_replace($payload, ['seed' => 'different']))->assertSessionHasErrors('request_id');
        $this->actingAs(User::factory()->create())->post(route('careers.friendlies.store', $this->career), $payload)->assertForbidden();
    }

    public function test_ai_cannot_credit_itself_or_apply_administrative_recommendations(): void
    {
        $payload = $this->payload();
        $club = $this->career->clubs()->findOrFail($payload['home_club_id']);
        $club->update(['balance_cents' => -5000]);
        $before = $this->world();
        $simulation = app(SimulateFriendly::class)->execute($this->owner, $this->career, $payload + ['balance_cents' => 99999999, 'administration_plan' => ['investment_budget_cents' => 99999999]]);
        $this->assertSame(0, $simulation->input['home']['administration_plan']['investment_budget_cents']);
        $this->assertNull($simulation->input['home']['administration_plan']['investment']);
        $this->assertSame($before, $this->world());
        $this->assertSame(-5000, $club->fresh()->balance_cents);
    }

    public function test_existing_v1_result_and_old_idempotency_key_still_work_after_upgrade(): void
    {
        $payload = $this->payload();
        $payload['mode'] = 'user_vs_ai';
        unset($payload['home_club_id']);
        $input = MatchFixture::make($payload['seed']);
        $result = (new FootballMatchEngine)->simulate($input);
        $legacy = MatchSimulation::create([
            'career_id' => $this->career->id, 'home_club_id' => $this->career->controlledClub->career_club_id,
            'away_club_id' => $payload['away_club_id'], 'request_id' => $payload['request_id'],
            'request_checksum' => ReplayHash::of([(int) $payload['away_club_id'], $payload['seed']]),
            'seed' => $payload['seed'], 'engine_version' => '1.0.0', 'input' => $input, 'result' => $result,
            'input_checksum' => ReplayHash::of($input), 'result_checksum' => ReplayHash::of($result),
        ]);
        $this->assertTrue($legacy->is(app(SimulateFriendly::class)->execute($this->owner, $this->career, $payload)));
        $legacy->refresh();
        $this->assertSame($legacy->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($legacy->input)));
        $this->get(route('careers.friendlies.show', [$this->career, $legacy]))->assertOk()->assertSee('Motor 1.0.0');
        $this->assertDatabaseCount('match_simulations', 1);
    }

    public function test_invalid_modes_and_malformed_values_do_not_break_the_form(): void
    {
        foreach ([['mode' => 'unknown'], ['home_club_id' => ['invalid']], ['seed' => ['invalid']]] as $invalid) {
            $this->from(route('careers.friendlies.create', $this->career))->post(route('careers.friendlies.store', $this->career), array_replace($this->payload(), $invalid))->assertSessionHasErrors();
            $this->get(route('careers.friendlies.create', $this->career))->assertOk();
        }
    }
}
