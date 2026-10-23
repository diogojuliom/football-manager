<?php

namespace Tests\Feature\Squads;

use App\Application\Careers\CreateCareer;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Domain\Tactics\Models\CareerLineup;
use App\Domain\Tactics\Models\CareerLineupPlayer;
use App\Domain\Tactics\Tactics;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SquadTest extends TestCase
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
        return app(CreateCareer::class)->execute($this->owner, [
            'name' => 'Minha carreira', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid(),
        ]);
    }

    private function payload(string $formation = '4-4-2'): array
    {
        return app(SquadSheet::class)->forCareer($this->career->fresh(), $formation)['settings'];
    }

    private function save(array $data)
    {
        return $this->put(route('careers.squad.update', $this->career), $data);
    }

    private function snapshot(): array
    {
        return [
            'lineups' => DB::table('career_lineups')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'players' => DB::table('career_lineup_players')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    public function test_roster_lists_all_attributes_and_draft_does_not_write_anything(): void
    {
        $response = $this->get(route('careers.squad.edit', $this->career));
        $response->assertOk()
            ->assertSee('Elenco e táticas')
            ->assertSee('sugestão inicial')
            ->assertSee('Posições secundárias')
            ->assertSee('Distribuição em campo')
            ->assertSee('data-pitch-player="0"', false)
            ->assertSee('data-starter-select="0"', false)
            ->assertSee('data-tactical-board', false)
            ->assertSee('data-tactical-model', false)
            ->assertSee('data-interaction-mode="fixed"', false)
            ->assertSee('data-draggable="false"', false)
            ->assertSee('<svg class="tactical-pitch-svg"', false)
            ->assertSee('GOL')
            ->assertSee('ZAG')
            ->assertSee('Aplicar formação')
            ->assertDontSee('draggable="true"', false)
            ->assertSee('Tendências da formação');
        foreach (Tactics::FORMATIONS as $formation => $positions) {
            $this->assertCount(11, $positions);
            $response->assertSee($formation);
        }
        foreach (Tactics::ATTRIBUTES as $label) {
            $response->assertSee($label);
        }
        $this->assertDatabaseCount('career_lineups', 0);
        $this->assertDatabaseCount('career_lineup_players', 0);
        $this->assertSame('2026-01-01', $this->career->fresh()->current_date->toDateString());
    }

    public function test_each_formation_and_tactical_option_can_be_saved_and_reloaded(): void
    {
        $formations = array_keys(Tactics::FORMATIONS);
        foreach ($formations as $index => $formation) {
            $data = $this->payload($formation);
            foreach (Tactics::OPTIONS as $field => $options) {
                $data[$field] = array_keys($options)[$index % count($options)];
            }
            $this->save($data)->assertRedirect(route('careers.squad.edit', $this->career))->assertSessionHasNoErrors();
            $lineup = CareerLineup::sole();
            $this->assertSame($index + 1, $lineup->revision);
            foreach (['formation', ...array_keys(Tactics::OPTIONS)] as $field) {
                $this->assertSame($data[$field], $lineup->$field);
            }
            $this->assertSame($data['starters'], $lineup->selections->where('unit', 'starter')->pluck('career_player_id')->all());
            $this->assertSame($data['substitutes'], $lineup->selections->where('unit', 'substitute')->pluck('career_player_id')->all());
            $this->assertDatabaseCount('career_lineup_players', 18);
            $reloaded = $this->payload($formation);
            $this->assertSame($data['starters'], $reloaded['starters']);
            $this->assertSame($data['substitutes'], $reloaded['substitutes']);
        }
    }

    public function test_invalid_formations_tactical_choices_and_revisions_are_rejected(): void
    {
        foreach (['formation' => '5-5-0', 'mentality' => 'ultra', 'pressure' => 'maximum', 'tempo' => 'instant', 'build_up' => 'random', 'revision' => -1] as $field => $value) {
            $this->save(array_replace($this->payload(), [$field => $value]))->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('career_lineups', 0);
    }

    public function test_rejects_wrong_counts_duplicate_players_and_wrong_goalkeeper_slots(): void
    {
        $base = $this->payload();
        $invalid = [];
        $data = $base;
        array_pop($data['starters']);
        $invalid[] = $data;
        $data = $base;
        $data['starters'][] = $data['substitutes'][0];
        $invalid[] = $data;
        $data = $base;
        $data['starters'][2] = $data['starters'][1];
        $invalid[] = $data;
        $data = $base;
        $data['substitutes'][0] = $data['starters'][0];
        $invalid[] = $data;
        $data = $base;
        $data['substitutes'][1] = $data['substitutes'][0];
        $invalid[] = $data;
        $data = $base;
        [$data['starters'][0], $data['starters'][1]] = [$data['starters'][1], $data['starters'][0]];
        $invalid[] = $data;
        $data = $base;
        $data['starters'] = array_combine(range(1, 11), $data['starters']);
        $invalid[] = $data;
        foreach ($invalid as $data) {
            $this->save($data)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('career_lineups', 0);
    }

    public function test_rejects_players_from_other_clubs_careers_or_missing_ids(): void
    {
        $another = $this->createCareer();
        $foreignIds = [
            $this->career->clubs()->where('id', '!=', $this->career->controlledClub->career_club_id)->first()->players()->first()->id,
            $another->controlledClub->club->players()->first()->id, 999999,
        ];
        foreach ($foreignIds as $id) {
            $data = $this->payload();
            $data['starters'][1] = $id;
            $this->save($data)->assertSessionHasErrors();
            $data = $this->payload();
            $data['substitutes'][0] = $id;
            $this->save($data)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('career_lineups', 0);
    }

    public function test_rejects_unfit_injured_and_suspended_players_in_starters_and_bench(): void
    {
        $base = $this->payload();
        foreach ([$base['starters'][1], $base['substitutes'][0]] as $id) {
            $player = $this->career->players()->findOrFail($id);
            foreach ([['fitness' => 0], ['injured_until' => '2026-01-01'], ['injured_until' => '2026-01-10'], ['suspended_matches' => 1]] as $invalid) {
                $player->update($invalid);
                $this->save($base)->assertSessionHasErrors();
                $player->update(['fitness' => 100, 'injured_until' => null, 'suspended_matches' => 0]);
            }
        }
        $this->assertDatabaseCount('career_lineups', 0);
        $player->update(['injured_until' => '2025-12-31']);
        $this->save($base)->assertSessionHasNoErrors();
    }

    public function test_revalidates_availability_of_previously_saved_players(): void
    {
        $this->save($this->payload())->assertSessionHasNoErrors();
        $data = $this->payload();
        $player = $this->career->players()->findOrFail($data['starters'][1]);
        $player->update(['suspended_matches' => 2]);
        $before = $this->snapshot();
        $this->save($data)->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
        $this->get(route('careers.squad.edit', $this->career))->assertOk()->assertSee('Suspenso por 2')->assertSee('Indisponível');
    }

    public function test_reserve_limit_comes_from_the_current_competition_snapshot(): void
    {
        $edition = CompetitionEdition::where('career_id', $this->career->id)->sole();
        $edition->update(['rules' => array_replace($edition->rules, ['max_substitutes' => 2])]);
        $data = $this->payload();
        $this->assertCount(2, $data['substitutes']);
        $extra = $this->career->controlledClub->club->players()->whereNotIn('id', [...$data['starters'], ...$data['substitutes']])->first()->id;
        $data['substitutes'][] = $extra;
        $this->save($data)->assertSessionHasErrors('substitutes');
        array_pop($data['substitutes']);
        $this->save($data)->assertSessionHasNoErrors();
        $edition->update(['rules' => array_replace($edition->rules, ['max_substitutes' => 0])]);
        $data = $this->payload();
        $data['substitutes'] = [];
        $this->save($data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('career_lineup_players', 11);
    }

    public function test_new_career_freezes_the_reserve_rule_from_its_template(): void
    {
        $ruleset = ClubTemplate::firstOrFail()->competition->ruleset;
        $ruleset->update(['max_substitutes' => 4]);
        $newCareer = $this->createCareer();
        $this->assertSame(4, CompetitionEdition::where('career_id', $newCareer->id)->sole()->rules['max_substitutes']);
        $ruleset->update(['max_substitutes' => 1]);
        $this->assertSame(4, app(SquadSheet::class)->maxSubstitutes($newCareer));
        $this->assertSame(7, app(SquadSheet::class)->maxSubstitutes($this->career));
    }

    public function test_empty_reserve_slots_are_optional_but_malformed_fields_are_not(): void
    {
        $data = $this->payload();
        $data['substitutes'] = ['', null];
        $this->save($data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('career_lineup_players', 11);
        foreach (['abc', [['id' => 1]]] as $invalid) {
            $data = $this->payload();
            $data['substitutes'] = $invalid;
            $this->save($data)->assertSessionHasErrors();
        }
    }

    public function test_malformed_values_can_be_rendered_after_validation_without_a_server_error(): void
    {
        $data = $this->payload();
        $data['revision'] = ['bad'];
        $data['starters'][0] = ['bad'];
        $data['substitutes'][0] = ['bad'];
        $this->from(route('careers.squad.edit', $this->career))->save($data)->assertSessionHasErrors();
        $this->get(route('careers.squad.edit', $this->career))->assertOk()->assertSee('Confira os dados');
    }

    public function test_stale_form_cannot_overwrite_a_newer_save(): void
    {
        $stale = $this->payload();
        $this->save($stale)->assertSessionHasNoErrors();
        $before = $this->snapshot();
        $stale['mentality'] = 'attacking';
        $this->save($stale)->assertSessionHasErrors('revision');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_squad_changes_do_not_affect_another_career_or_advance_time(): void
    {
        $other = $this->createCareer();
        $otherData = app(SquadSheet::class)->forCareer($other)['settings'];
        app(SaveSquad::class)->execute($this->owner, $other, $otherData);
        $before = DB::table('career_lineup_players')->where('career_id', $other->id)->get()->toJson();
        $data = $this->payload('4-3-3');
        $data['mentality'] = 'attacking';
        $this->save($data)->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('career_lineup_players')->where('career_id', $other->id)->get()->toJson());
        $this->assertSame('2026-01-01', $this->career->fresh()->current_date->toDateString());
        $this->assertSame('balanced', $other->controlledClub->club->lineup->mentality);
        $this->assertSame(90, $this->career->matches()->where('status', 'scheduled')->count());
    }

    public function test_processing_states_block_saves_but_pre_match_allows_edits(): void
    {
        $data = $this->payload();
        foreach ([CareerState::Processing, CareerState::AwaitingMatch] as $state) {
            $this->career->update(['state' => $state]);
            $this->save($data)->assertSessionHasErrors('lineup');
            $this->get(route('careers.squad.edit', $this->career))->assertOk()->assertSee('edição está bloqueada');
        }
        $this->career->update(['state' => CareerState::PreMatch]);
        $this->save($data)->assertSessionHasNoErrors();
    }

    public function test_other_users_and_guests_cannot_view_or_change_squads(): void
    {
        $data = $this->payload();
        $this->actingAs(User::factory()->create())->get(route('careers.squad.edit', $this->career))->assertForbidden();
        $this->save($data)->assertForbidden();
        $this->post('/logout');
        $this->get(route('careers.squad.edit', $this->career))->assertRedirect(route('login'));
        $this->save($data)->assertRedirect(route('login'));
        $this->assertDatabaseCount('career_lineups', 0);
    }

    public function test_application_service_also_enforces_ownership(): void
    {
        $this->expectException(AuthorizationException::class);
        app(SaveSquad::class)->execute(User::factory()->create(), $this->career, $this->payload());
    }

    public function test_failed_save_restores_previous_lineup_and_tactics(): void
    {
        $this->save($this->payload())->assertSessionHasNoErrors();
        $data = $this->payload();
        $data['mentality'] = 'attacking';
        $before = $this->snapshot();
        CareerLineupPlayer::creating(function (): void {
            throw new RuntimeException('Interrupted save');
        });
        try {
            app(SaveSquad::class)->execute($this->owner, $this->career, $data);
            $this->fail('The failure must abort the entire save.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted save', $exception->getMessage());
        } finally {
            CareerLineupPlayer::flushEventListeners();
            CareerLineupPlayer::clearBootedModels();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_database_rejects_duplicate_selections_and_foreign_club_players(): void
    {
        $this->save($this->payload())->assertSessionHasNoErrors();
        $selection = CareerLineupPlayer::firstOrFail();
        try {
            $selection->replicate()->save();
            $this->fail('A duplicate selection was accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('23000', $exception->getMessage());
        }
        $foreign = $this->career->clubs()->where('id', '!=', $selection->career_club_id)->first()->players()->first();
        $this->expectException(QueryException::class);
        $selection->update(['career_player_id' => $foreign->id]);
    }

    public function test_outfield_players_may_play_out_of_position_with_a_visible_warning(): void
    {
        $data = $this->payload();
        [$data['starters'][1], $data['starters'][9]] = [$data['starters'][9], $data['starters'][1]];
        $this->save($data)->assertSessionHasNoErrors();
        $this->get(route('careers.squad.edit', $this->career))->assertOk()->assertSee('Fora da posição');
    }

    public function test_saved_squad_is_restored_after_logout_and_login(): void
    {
        $this->owner->update(['password' => 'local-test-password']);
        $data = $this->payload('4-2-3-1');
        $data['pressure'] = 'high';
        $this->save($data)->assertSessionHasNoErrors();
        $before = $this->snapshot();
        $this->post('/logout');
        $this->travel(30)->days();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'local-test-password'])->assertRedirect();
        $this->get(route('careers.squad.edit', $this->career))->assertOk()->assertSee('4-2-3-1');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('high', CareerLineup::sole()->pressure);
    }
}
