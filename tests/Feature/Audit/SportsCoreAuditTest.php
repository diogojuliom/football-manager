<?php

namespace Tests\Feature\Audit;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Competitions\StandingsService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerEvent;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SportsCoreAuditTest extends TestCase
{
    use RefreshDatabase;

    private function career(User $owner): Career
    {
        $this->seed();

        return app(CreateCareer::class)->execute($owner, ['name' => 'Audit', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
    }

    public function test_email_header_separators_are_rejected_in_all_authentication_flows(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password'] as $path) {
            $this->travel(1)->minutes();
            foreach (["ana\r\n @example.com", "ana\n@example.com", "ana\r@example.com"] as $email) {
                $this->post($path, ['email' => $email, 'name' => 'Ana', 'token' => 'invalid',
                    'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertSessionHasErrors('email');
            }
        }
        $this->travelBack();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_invalid_authentication_values_can_be_rendered_after_validation(): void
    {
        foreach (['/login', '/register'] as $path) {
            $this->from($path)->post($path, ['name' => ['bad'], 'email' => ['bad'], 'password' => 'short'])
                ->assertSessionHasErrors()->assertRedirect($path);
            $this->get($path)->assertOk()->assertSee('Confira os dados informados');
        }
    }

    public function test_invalid_career_values_can_be_rendered_after_validation(): void
    {
        $owner = User::factory()->create();
        $this->seed();
        $this->actingAs($owner)->from('/careers/create')->post('/careers', [
            'name' => ['bad'], 'coach_name' => ['bad'], 'request_id' => ['bad'], 'club_template_id' => ['bad'],
        ])->assertSessionHasErrors()->assertRedirect('/careers/create');
        $this->get('/careers/create')->assertOk()->assertSee('Confira os dados informados');
        $this->assertDatabaseCount('careers', 0);
    }

    public function test_daily_recovery_uses_one_update_and_preserves_injury_boundaries_and_other_careers(): void
    {
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $other = $this->career($owner);
        $career->players()->update(['fitness' => 50]);
        $injured = $career->players()->firstOrFail();
        $injured->update(['injured_until' => '2026-01-02']);
        $other->players()->update(['fitness' => 20]);
        DB::enableQueryLog();
        app(CareerProgressionService::class)->execute($owner, $career, [
            'action' => 'ADVANCE_ONE_DAY', 'request_id' => (string) Str::uuid(), 'revision' => 0,
        ]);
        $updates = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^update ["`]?career_players/i', $query['query']));
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(1, $updates, 'Recovery must not issue one UPDATE per player.');
        $this->assertSame(50, $injured->fresh()->fitness);
        $this->assertSame(199, $career->players()->where('fitness', 58)->count());
        $this->assertSame(200, $other->players()->where('fitness', 20)->count());
    }

    public function test_tables_and_dashboard_do_not_select_full_match_snapshots(): void
    {
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $edition = CompetitionEdition::where('career_id', $career->id)->sole();
        DB::enableQueryLog();
        app(StandingsService::class)->standings($edition);
        app(StandingsService::class)->scorers($edition);
        $this->actingAs($owner)->get(route('careers.show', $career))->assertOk();
        $queries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'official_match_results'));
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select\s+(?:["`]?\w+["`]?\.)?\*/i', $query['query']);
        }
    }

    public function test_jump_and_daily_progression_have_identical_sports_effects_with_decisions_and_injuries(): void
    {
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $career->players()->update(['fitness' => 20]);
        $career->players()->firstOrFail()->update(['injured_until' => '2026-01-04']);
        $humanId = $career->controlledClub->career_club_id;
        $career->matches()->where('round', 1)->where('home_club_id', '!=', $humanId)->where('away_club_id', '!=', $humanId)
            ->firstOrFail()->update(['scheduled_on' => '2026-01-05']);
        CareerEvent::create(['career_id' => $career->id, 'career_season_id' => $career->seasons()->sole()->id,
            'event_key' => 'audit-decision', 'kind' => 'administrative_decision', 'title' => 'Decision',
            'scheduled_on' => '2026-01-05', 'requires_decision' => true, 'status' => 'pending']);
        $outcomes = [];
        foreach (['ADVANCE_TO_NEXT_MATCH', 'ADVANCE_ONE_DAY'] as $action) {
            DB::beginTransaction();
            try {
                for ($step = 0; $step < 20; $step++) {
                    $current = $career->fresh();
                    if ($current->state === CareerState::PreMatch) {
                        break;
                    }
                    $decision = app(CareerCalendar::class)->decision($current);
                    app(CareerProgressionService::class)->execute($owner, $current, [
                        'action' => $decision ? 'ACKNOWLEDGE_EVENT' : $action,
                        'event_id' => $decision?->id, 'request_id' => (string) Str::uuid(), 'revision' => $current->progression_revision,
                    ]);
                }
                $this->assertSame(CareerState::PreMatch, $career->fresh()->state);
                $this->assertSame('2026-01-14', $career->fresh()->current_date->toDateString());
                $this->assertDatabaseCount('official_match_results', 1);
                $outcomes[] = [
                    'players' => $career->players()->orderBy('id')->get(['id', 'fitness', 'suspended_matches'])->toArray(),
                    'results' => OfficialMatchResult::orderBy('match_id')->get(['match_id', 'input_checksum', 'result_checksum'])->toArray(),
                    'days' => DB::table('career_processed_days')->orderBy('processed_on')->pluck('processed_on')->all(),
                    'events' => CareerEvent::orderBy('id')->get(['event_key', 'status', 'processed_on'])->toArray(),
                ];
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame($outcomes[0], $outcomes[1]);
    }
}
