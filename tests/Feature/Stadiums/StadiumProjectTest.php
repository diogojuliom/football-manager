<?php

namespace Tests\Feature\Stadiums;

use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Stadiums\StadiumProjectService;
use App\Domain\AI\AdministrationPlanner;
use App\Domain\Career\Models\StadiumProject;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class StadiumProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function career(User $owner)
    {
        return app(CreateCareer::class)->execute($owner, [
            'name' => 'Estádio', 'coach_name' => 'Ana', 'club_template_id' => ClubTemplate::firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ]);
    }

    private function advance(User $owner, $career)
    {
        return app(CareerProgressionService::class)->execute($owner, $career, [
            'action' => 'ADVANCE_ONE_DAY', 'request_id' => (string) Str::uuid(),
            'revision' => $career->fresh()->progression_revision,
        ]);
    }

    public function test_expansion_is_paid_once_and_only_changes_capacity_on_the_due_internal_day(): void
    {
        config()->set('stadium_projects.expansion.duration_days', 2);
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $this->actingAs($owner);
        $club = $career->controlledClub->club;
        $stadium = $club->stadium;
        $before = $club->balance_cents;
        $capacity = $stadium->capacity;
        $revision = $career->fresh()->progression_revision;

        $this->post(route('careers.stadium.projects.store', $career), ['type' => 'expansion', 'revision' => $revision])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('careers.stadium.show', $career));
        $project = StadiumProject::where('career_id', $career->id)->sole();
        $this->assertSame('2026-01-03', $project->due_on->toDateString());
        $this->assertSame($before - $project->cost_cents, $club->fresh()->balance_cents);
        $this->assertSame($capacity, $stadium->fresh()->capacity);
        $this->assertSame(-$project->cost_cents, CareerFinancialTransaction::where('source_key', 'stadium-project:'.$project->id)->sole()->amount_cents);
        $this->post(route('careers.stadium.projects.store', $career), ['type' => 'modernization', 'revision' => $career->fresh()->progression_revision])
            ->assertSessionHasErrors('project');
        $this->travel(40)->days();
        $this->assertSame($capacity, $stadium->fresh()->capacity);
        $this->advance($owner, $career);
        $this->assertSame($capacity, $stadium->fresh()->capacity);
        $this->advance($owner, $career);
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame($capacity + 5000, $stadium->fresh()->capacity);
        $this->assertSame($capacity + 5000, $stadium->fresh()->available_capacity);
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame('2026-01-03', $project->fresh()->completed_on->toDateString());
        $this->advance($owner, $career);
        $this->assertSame($capacity + 5000, $stadium->fresh()->capacity);
        $this->assertSame(1, CareerFinancialTransaction::where('source_key', 'stadium-project:'.$project->id)->count());
        $this->get(route('careers.stadium.show', $career))->assertOk()->assertSee('Concluída em 03/01/2026');
        $this->get(route('careers.finances.show', $career))->assertOk()->assertSee('Obra no estádio');
    }

    public function test_modernization_rejects_insufficient_funds_stale_revision_and_other_owner(): void
    {
        config()->set('stadium_projects.modernization.duration_days', 1);
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $club = $career->controlledClub->club;
        $stadium = $club->stadium;
        $this->actingAs(User::factory()->create())
            ->post(route('careers.stadium.projects.store', $career), ['type' => 'modernization', 'revision' => 0])->assertForbidden();
        $this->actingAs($owner);
        $club->update(['balance_cents' => 1]);
        $this->post(route('careers.stadium.projects.store', $career), ['type' => 'modernization', 'revision' => 0])->assertSessionHasErrors('project');
        $this->assertDatabaseCount('stadium_projects', 0);
        $club->update(['balance_cents' => 30000000]);
        $this->post(route('careers.stadium.projects.store', $career), ['type' => 'modernization', 'revision' => 9])->assertSessionHasErrors('revision');
        $this->post(route('careers.stadium.projects.store', $career), ['type' => 'modernization', 'revision' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, $stadium->fresh()->infrastructure_level);
        $this->assertSame('2026-01-02', StadiumProject::sole()->due_on->toDateString());
        $this->advance($owner, $career);
        $this->assertSame('2026-01-02', $career->fresh()->current_date->toDateString());
        $this->assertSame('completed', StadiumProject::sole()->status);
        $this->assertSame(4, $stadium->fresh()->infrastructure_level);
        $this->assertSame(1, CareerFinancialTransaction::where('kind', 'investment')->count());
        $this->assertSame(0, $this->career(User::factory()->create())->controlledClub->club->stadium->projects()->count());
    }

    public function test_ai_invests_only_during_authorized_progression_and_does_not_duplicate_project(): void
    {
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $ai = $career->clubs()->whereKeyNot($career->controlledClub->career_club_id)->firstOrFail();
        $ai->update(['balance_cents' => 100000000]);
        $career->matches()->where('scheduled_on', '<', '2026-02-01')->update(['scheduled_on' => '2026-02-07']);
        $career->update(['current_date' => '2026-01-31']);
        $this->assertDatabaseCount('stadium_projects', 0);
        $operation = $this->advance($owner, $career);
        $project = StadiumProject::where('career_id', $career->id)->where('career_club_id', $ai->id)->firstOrFail();
        $this->assertSame('ai', $project->decision_source);
        $this->assertSame('2026-02-01', $project->started_on->toDateString());
        $this->assertSame(100000000 - $project->cost_cents, $ai->fresh()->balance_cents);
        $this->assertSame(1, StadiumProject::where('career_club_id', $ai->id)->count());
        app(CareerProgressionService::class)->resume($owner, $career, $operation);
        $this->assertSame(1, StadiumProject::where('career_club_id', $ai->id)->count());
    }

    public function test_next_event_stops_on_completion_and_work_due_on_match_day_finishes_before_pre_match(): void
    {
        config()->set('stadium_projects.expansion.duration_days', 2);
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $stadium = $career->controlledClub->club->stadium;
        $initialCapacity = $stadium->capacity;
        $this->actingAs($owner)->post(route('careers.stadium.projects.store', $career), [
            'type' => 'expansion', 'revision' => $career->fresh()->progression_revision,
        ])->assertSessionHasNoErrors();
        $operation = app(CareerProgressionService::class)->execute($owner, $career, [
            'action' => 'ADVANCE_TO_NEXT_EVENT', 'request_id' => (string) Str::uuid(),
            'revision' => $career->fresh()->progression_revision,
        ]);
        $this->assertSame('2026-01-03', $operation->result['current_date']);
        $this->assertSame($initialCapacity + 5000, $stadium->fresh()->capacity);
        app(CareerProgressionService::class)->resume($owner, $career, $operation);
        $this->assertSame($initialCapacity + 5000, $stadium->fresh()->capacity);

        config()->set('stadium_projects.modernization.duration_days', 11);
        $level = $stadium->fresh()->infrastructure_level;
        $this->post(route('careers.stadium.projects.store', $career), [
            'type' => 'modernization', 'revision' => $career->fresh()->progression_revision,
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-01-14', StadiumProject::where('type', 'modernization')->sole()->due_on->toDateString());
        $operation = app(CareerProgressionService::class)->execute($owner, $career, [
            'action' => 'ADVANCE_TO_NEXT_MATCH', 'request_id' => (string) Str::uuid(),
            'revision' => $career->fresh()->progression_revision,
        ]);
        $this->assertSame('pre_match', $operation->result['state']);
        $this->assertSame($level + 1, $stadium->fresh()->infrastructure_level);
    }

    public function test_failed_progression_rolls_back_completion_and_resume_applies_it_once(): void
    {
        config()->set('stadium_projects.expansion.duration_days', 1);
        $owner = User::factory()->create();
        $career = $this->career($owner);
        $stadium = $career->controlledClub->club->stadium;
        $capacity = $stadium->capacity;
        $this->actingAs($owner)->post(route('careers.stadium.projects.store', $career), [
            'type' => 'expansion', 'revision' => $career->fresh()->progression_revision,
        ])->assertSessionHasNoErrors();

        $projects = \Mockery::mock(StadiumProjectService::class, [app(AdministrationPlanner::class)])->makePartial();
        $calls = 0;
        $projects->shouldReceive('planForAi')->andReturnUsing(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('Falha simulada após a conclusão.');
            }
        });
        app()->instance(StadiumProjectService::class, $projects);
        $progression = app(CareerProgressionService::class);
        $operation = $progression->authorizeOperation($owner, $career, [
            'action' => 'ADVANCE_ONE_DAY', 'request_id' => (string) Str::uuid(),
            'revision' => $career->fresh()->progression_revision,
        ]);
        try {
            $progression->resume($owner, $career, $operation);
            $this->fail('A falha simulada deveria interromper a progressão.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha simulada após a conclusão.', $exception->getMessage());
        }
        $this->assertSame('active', StadiumProject::sole()->status);
        $this->assertSame($capacity, $stadium->fresh()->capacity);
        $this->assertSame('2026-01-01', $career->fresh()->current_date->toDateString());
        $this->assertSame('pending', $operation->fresh()->status);

        app()->instance(StadiumProjectService::class, new StadiumProjectService(app(AdministrationPlanner::class)));
        app(CareerProgressionService::class)->resume($owner, $career, $operation);
        app(CareerProgressionService::class)->resume($owner, $career, $operation);
        $this->assertSame('completed', StadiumProject::sole()->status);
        $this->assertSame($capacity + 5000, $stadium->fresh()->capacity);
        $this->assertSame(1, CareerFinancialTransaction::where('kind', 'investment')->count());
    }
}
