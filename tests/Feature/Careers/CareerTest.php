<?php

namespace Tests\Feature\Careers;

use App\Application\Careers\CreateCareer;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClubController;
use App\Domain\Career\Models\CareerPlayer;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CareerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Rumo ao título',
            'coach_name' => 'Ana Silva',
            'club_template_id' => ClubTemplate::orderBy('id')->firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    private function create(?User $owner = null, array $overrides = []): Career
    {
        return app(CreateCareer::class)->execute($owner ?? User::factory()->create(), $this->input($overrides));
    }

    public function test_creates_a_complete_career_with_independent_copies_and_calendar(): void
    {
        $user = User::factory()->create();
        $input = $this->input();
        $this->actingAs($user)->post(route('careers.store'), $input)->assertRedirect();
        $career = $user->careers()->sole();
        $this->assertSame('2026-01-01', $career->current_date->toDateString());
        $this->assertSame(CareerState::AwaitingDecision, $career->state);
        $this->assertSame(1, $career->current_season_number);
        $this->assertSame('Ana Silva', $career->coach_name);
        $this->assertSame('1.0.0', $career->version->version);
        $this->assertCount(10, $career->clubs);
        $this->assertCount(200, $career->players);
        $this->assertCount(1, $career->memberships);
        $this->assertSame($user->id, $career->memberships->sole()->user_id);
        $this->assertSame('owner', $career->memberships->sole()->role);
        $this->assertDatabaseCount('career_stadiums', 10);
        $this->assertDatabaseCount('career_club_support', 10);
        $this->assertDatabaseCount('career_seasons', 1);
        $this->assertDatabaseCount('competition_editions', 1);
        $this->assertDatabaseCount('competition_entries', 10);
        $this->assertSame(9, CareerClubController::where('type', 'ai')->whereNull('user_id')->count());
        $this->assertSame($user->id, $career->controlledClub->user_id);
        $this->assertSame($input['club_template_id'], $career->controlledClub->club->club_template_id);
        $this->assertSame(10, CompetitionEdition::firstOrFail()->rules['club_count']);

        foreach ($career->clubs as $club) {
            $template = ClubTemplate::with(['players', 'stadium'])->findOrFail($club->club_template_id);
            $this->assertSame($template->name, $club->name);
            $this->assertSame($template->initial_balance_cents, $club->balance_cents);
            $this->assertSame($club->initial_balance_cents, $club->balance_cents);
            $this->assertSame($template->default_ticket_price_cents, $club->default_ticket_price_cents);
            $this->assertSame($template->potential_supporters, $club->support->potential_supporters);
            $this->assertSame($template->stadium->capacity, $club->stadium->capacity);
            $this->assertSame($template->stadium->fixed_operating_cost_cents, $club->stadium->fixed_operating_cost_cents);
            foreach ($club->players as $player) {
                $base = $template->players->firstWhere('id', $player->player_template_id);
                $this->assertNotNull($base);
                foreach (['name', 'age', 'primary_position', 'secondary_positions', ...\App\Domain\GameDatabase\Models\PlayerTemplate::ATTRIBUTES] as $attribute) {
                    $this->assertSame($base->$attribute, $player->$attribute);
                }
                $this->assertSame($base->initial_fitness, $player->fitness);
                $this->assertSame($base->initial_morale, $player->morale);
            }
        }

        $matches = $career->matches;
        $this->assertCount(90, $matches);
        $this->assertSame(18, $matches->pluck('round')->unique()->count());
        $this->assertSame(90, $matches->map(fn ($m) => "$m->home_club_id:$m->away_club_id")->unique()->count());
        foreach ($matches->groupBy('round') as $round) {
            $this->assertCount(5, $round);
            $participants = $round->pluck('home_club_id')->merge($round->pluck('away_club_id'));
            $this->assertCount(10, $participants->unique());
            $this->assertSame(1, $round->pluck('scheduled_on')->map->toDateString()->unique()->count());
        }
        foreach ($career->clubs as $club) {
            $this->assertSame(9, $matches->where('home_club_id', $club->id)->count());
            $this->assertSame(9, $matches->where('away_club_id', $club->id)->count());
        }
        $this->assertSame('2026-01-14', $career->matches()->orderBy('scheduled_on')->first()->scheduled_on->toDateString());
        $this->actingAs($user)->get(route('careers.show', $career))->assertOk()->assertSee('Ana Silva')->assertSee('01/01/2026')->assertSee('Calendário da liga');
    }

    public function test_multiple_careers_do_not_share_mutable_state_or_change_templates(): void
    {
        $owner = User::factory()->create();
        $one = $this->create($owner);
        $two = $this->create($owner);
        $first = $one->controlledClub->club;
        $second = $two->controlledClub->club;
        $base = ClubTemplate::findOrFail($first->club_template_id);
        $this->assertNotSame($first->id, $second->id);
        $first->update(['name' => 'Clube alterado', 'balance_cents' => 12345]);
        $first->stadium->update(['capacity' => 99999]);
        $first->support->update(['loyalty' => 5]);
        $first->players->first()->update(['fitness' => 7, 'passing' => 2]);
        $one->update(['current_date' => '2026-02-12', 'state' => CareerState::PreMatch]);
        $one->matches()->first()->update(['status' => 'finished']);

        $this->assertSame($base->name, $second->fresh()->name);
        $this->assertSame($base->initial_balance_cents, $second->fresh()->balance_cents);
        $this->assertSame($base->stadium->capacity, $second->stadium->capacity);
        $this->assertSame($base->initial_loyalty, $second->support->loyalty);
        $this->assertSame(100, $second->players->first()->fitness);
        $this->assertSame('2026-01-01', $two->fresh()->current_date->toDateString());
        $this->assertSame(CareerState::AwaitingDecision, $two->fresh()->state);
        $this->assertSame(90, $two->matches()->where('status', 'scheduled')->count());
        $this->assertSame(100, $base->players->first()->initial_fitness);
        $this->seed();
        $this->assertSame(12345, $first->fresh()->balance_cents);
        $this->assertSame(7, $first->players()->first()->fitness);
        $this->assertDatabaseCount('career_players', 400);
    }

    public function test_career_snapshots_do_not_change_when_base_data_is_edited(): void
    {
        $career = $this->create();
        $club = $career->controlledClub->club;
        $template = ClubTemplate::findOrFail($club->club_template_id);
        $name = $club->name;
        $capacity = $club->stadium->capacity;
        $passing = $club->players->first()->passing;
        $template->update(['name' => 'Edited base club', 'initial_balance_cents' => 1]);
        $template->stadium->update(['capacity' => 99999]);
        $template->players->first()->update(['passing' => 0]);
        $template->competition->ruleset->update(['points_for_win' => 4]);
        $this->assertSame($name, $club->fresh()->name);
        $this->assertNotSame(1, $club->fresh()->balance_cents);
        $this->assertSame($capacity, $club->stadium->fresh()->capacity);
        $this->assertSame($passing, $club->players->first()->fresh()->passing);
        $this->assertSame(3, CompetitionEdition::where('career_id', $career->id)->sole()->rules['points_for_win']);
    }

    public function test_reopening_after_logout_preserves_saved_progress_without_real_time_advancement(): void
    {
        $owner = User::factory()->create(['password' => 'secret-password']);
        $career = $this->create($owner);
        $career->update(['current_date' => '2026-02-03', 'state' => CareerState::PreMatch]);
        $club = $career->controlledClub->club;
        $club->update(['balance_cents' => 76543]);
        $this->actingAs($owner)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->travel(2)->years();
        $this->post('/login', ['email' => $owner->email, 'password' => 'secret-password'])->assertRedirect();
        $this->get(route('careers.show', $career))->assertOk()->assertSee('03/02/2026')->assertSee('Pré-jogo')->assertSee('765,43');
        $this->assertSame('2026-02-03', $career->fresh()->current_date->toDateString());
        $this->assertSame(90, $career->matches()->where('status', 'scheduled')->count());
    }

    public function test_other_users_cannot_list_or_open_the_career(): void
    {
        $career = $this->create();
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('careers.index'))->assertOk()->assertDontSee('Rumo ao título');
        $this->get(route('careers.show', $career))->assertForbidden();
        $this->get(route('careers.show', 999999))->assertNotFound();
    }

    public function test_guest_cannot_create_or_resume_careers(): void
    {
        $career = $this->create();
        foreach (['careers.index', 'careers.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->get(route('careers.show', $career))->assertRedirect(route('login'));
        $this->post(route('careers.store'), $this->input())->assertRedirect(route('login'));
        $this->assertDatabaseCount('careers', 1);
    }

    public function test_empty_careers_page_offers_a_direct_link_to_create(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('careers.index'))
            ->assertOk()
            ->assertSee('Sua jornada à beira do gramado começa agora.')
            ->assertSee('Criar Minha Primeira Carreira')
            ->assertSee('href="'.route('careers.create').'"', false)
            ->assertDontSee('Minhas Carreiras Salvas');
    }

    public function test_saved_careers_page_uses_the_owners_current_saved_data(): void
    {
        $owner = User::factory()->create();
        $career = $this->create($owner, ['name' => 'Meu desafio', 'coach_name' => 'Lia Costa']);
        $other = $this->create(User::factory()->create(), ['name' => 'Save alheio']);
        $career->update(['current_date' => '2026-02-03', 'current_season_number' => 2]);
        $career->controlledClub->club->update(['balance_cents' => 76543]);

        $this->actingAs($owner)->get(route('careers.index'))
            ->assertOk()
            ->assertSee('Minhas Carreiras Salvas')
            ->assertSee('Meu desafio')
            ->assertSee('Lia Costa')
            ->assertSee($career->controlledClub->club->name)
            ->assertSee('03/02/2026')
            ->assertSee('R$ 765,43')
            ->assertSee('href="'.route('careers.show', $career).'"', false)
            ->assertDontSee($other->name)
            ->assertDontSee('Sua jornada à beira do gramado começa agora.');

        $this->get(route('careers.index', ['page' => 99]))
            ->assertOk()
            ->assertSee('Não há carreiras nesta página.')
            ->assertDontSee('Sua jornada à beira do gramado começa agora.');
    }

    public function test_new_career_page_preserves_selected_club_and_field_errors_after_validation(): void
    {
        $user = User::factory()->create();
        $club = ClubTemplate::orderBy('id')->firstOrFail();
        $input = $this->input(['coach_name' => '', 'club_template_id' => $club->id]);

        $this->actingAs($user)->from(route('careers.create'))
            ->post(route('careers.store'), $input)
            ->assertRedirect(route('careers.create'))
            ->assertSessionHasErrors('coach_name');

        $this->get(route('careers.create'))
            ->assertOk()
            ->assertSee('Iniciar Nova Carreira')
            ->assertSee('name="club_template_id" value="'.$club->id.'" checked', false)
            ->assertSee('value="'.$input['request_id'].'"', false)
            ->assertSee('value="Rumo ao título"', false)
            ->assertSee('id="coach-error"', false);
    }

    public function test_saved_careers_pagination_keeps_existing_careers_reachable(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 21) as $number) {
            $this->create($owner, ['name' => "Save $number"]);
        }

        $this->actingAs($owner)->get(route('careers.index'))
            ->assertOk()
            ->assertSee('Save 21')
            ->assertDontSee('Save 1</span>', false)
            ->assertSee('aria-label="Páginas das carreiras"', false)
            ->assertSee('rel="next"', false);

        $this->get(route('careers.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Save 1')
            ->assertDontSee('Save 21')
            ->assertSee('rel="prev"', false);
    }

    public function test_form_shows_clubs_and_rejects_invalid_creation_data(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('careers.create'))->assertOk()->assertSee('Aurora Futebol Clube')->assertSee('request_id');
        foreach ([['name' => ''], ['coach_name' => ''], ['name' => str_repeat('a', 101)], ['club_template_id' => 999999], ['request_id' => 'invalid']] as $invalid) {
            $this->post(route('careers.store'), $this->input($invalid))->assertSessionHasErrors(array_key_first($invalid));
        }
        $this->assertDatabaseCount('careers', 0);
    }

    public function test_creation_ignores_forged_owner_date_and_state(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post(route('careers.store'), $this->input([
            'user_id' => $other->id, 'current_date' => '2099-12-31', 'state' => 'finished', 'balance_cents' => 999999999,
        ]))->assertRedirect();
        $career = Career::sole();
        $this->assertSame($owner->id, $career->user_id);
        $this->assertSame('2026-01-01', $career->current_date->toDateString());
        $this->assertSame(CareerState::AwaitingDecision, $career->state);
    }

    public function test_duplicate_creation_request_returns_the_same_complete_career(): void
    {
        $user = User::factory()->create();
        $input = $this->input();
        $first = app(CreateCareer::class)->execute($user, $input);
        $second = app(CreateCareer::class)->execute($user, $input);
        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('careers', 1);
        $this->assertDatabaseCount('career_players', 200);
        $this->assertDatabaseCount('matches', 90);
        $this->expectException(ValidationException::class);
        app(CreateCareer::class)->execute($user, array_replace($input, ['name' => 'Other payload']));
    }

    public function test_creation_request_key_is_scoped_to_its_owner(): void
    {
        $input = $this->input();
        $one = app(CreateCareer::class)->execute(User::factory()->create(), $input);
        $two = app(CreateCareer::class)->execute(User::factory()->create(), $input);
        $this->assertNotSame($one->id, $two->id);
    }

    public function test_incomplete_base_universe_is_rejected_without_partial_data(): void
    {
        ClubTemplate::firstOrFail()->stadium->delete();
        $this->actingAs(User::factory()->create())->post(route('careers.store'), $this->input())->assertSessionHasErrors('club_template_id');
        $this->assertDatabaseCount('careers', 0);
        $this->assertDatabaseCount('career_clubs', 0);
    }

    public function test_mid_creation_failure_rolls_back_every_career_table_and_can_be_retried(): void
    {
        $user = User::factory()->create();
        $input = $this->input();
        CareerPlayer::creating(function (): void {
            throw new RuntimeException('Creation interrupted');
        });
        try {
            app(CreateCareer::class)->execute($user, $input);
            $this->fail('An interrupted creation was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Creation interrupted', $exception->getMessage());
        } finally {
            CareerPlayer::flushEventListeners();
            CareerPlayer::clearBootedModels();
        }
        foreach (['careers', 'career_memberships', 'career_clubs', 'career_players', 'career_stadiums', 'career_club_support', 'career_club_controllers', 'career_seasons', 'competition_editions', 'competition_entries', 'matches'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('player_templates', 200);
        $career = app(CreateCareer::class)->execute($user, $input);
        $this->assertCount(200, $career->players);
    }

    public function test_database_prevents_cross_career_links(): void
    {
        $one = $this->create();
        $two = $this->create();
        // A career with no matching templates avoids masking the FK check with a unique-key failure.
        $empty = $two->replicate();
        $empty->creation_key = (string) Str::uuid();
        $empty->save();
        foreach (['career_players', 'career_stadiums', 'career_club_support', 'career_club_controllers', 'competition_entries'] as $table) {
            $record = DB::table($table)->where('career_id', $one->id)->first();
            try {
                DB::table($table)->where('id', $record->id)->update(['career_id' => $empty->id]);
                $this->fail("Cross-career linkage accepted in $table.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
            }
        }
        try {
            $one->matches()->first()->update(['home_club_id' => $two->clubs->first()->id]);
            $this->fail('A match accepted a club from another career.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }
    }

    public function test_names_are_escaped_when_rendering(): void
    {
        $career = $this->create(null, ['name' => '<script>alert(1)</script>']);
        $this->actingAs($career->owner)->get(route('careers.show', $career))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
