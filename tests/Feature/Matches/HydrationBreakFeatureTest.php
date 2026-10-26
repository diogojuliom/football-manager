<?php

namespace Tests\Feature\Matches;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Matches\InteractiveMatchService;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Engine\V6\Simulation;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HydrationBreakFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_interactive_break_is_persisted_idempotent_adjustable_and_recoverable(): void
    {
        [$owner, $career] = $this->readyCareer('Paradas interativas');
        $service = app(InteractiveMatchService::class);
        $match = app(CareerCalendar::class)->nextMatch($career);
        $session = $service->start($owner, $career, $match);
        $running = $service->control($owner, $career, $session, $this->request(0, ['action' => 'resume']));
        $advanceRequest = $this->request($running['revision'], ['minutes' => 60]);
        $stopped = $service->advance($owner, $career, $session, $advanceRequest);

        $this->assertTrue($stopped['paused']);
        $this->assertSame(30, $stopped['minute']);
        $this->assertSame('hydration_break', $stopped['state']['status']);
        $this->assertSame('active', $stopped['state']['hydration_breaks']['first_half']['status']);
        $this->assertSame($stopped, $service->advance($owner, $career, $session, $advanceRequest));
        $this->assertSame($stopped, $service->response($service->find($owner, $career, $match)));
        $this->actingAs($owner)->get(route('careers.matches.live.show', [$career, $match]))
            ->assertOk()->assertSee('Parada técnica para hidratação')->assertSee('Pausa regulamentar de três minutos');

        try {
            $service->advance($owner, $career, $session, $this->request($stopped['revision'], ['minutes' => 1]));
            $this->fail('A parada ativa permitiu avanço sem confirmação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('match', $exception->errors());
        }
        $this->assertSame($stopped, $service->response($session->fresh()));

        $eventsBefore = $stopped['state']['events'];
        $rngBefore = $stopped['state']['rng_counter'];
        $human = $session->meta['human_side'];
        $team = $stopped['state']['teams'][$human];
        $adjusted = $service->decide($owner, $career, $session, $this->request($stopped['revision'], [
            'type' => 'tactics',
            'payload' => ['formation' => '4-3-3', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'heavy'],
        ]));
        $adjusted = $service->decide($owner, $career, $session, $this->request($adjusted['revision'], [
            'type' => 'substitution',
            'payload' => ['out' => $team['active'][1], 'in' => $team['bench'][1]],
        ]));
        $this->assertSame($rngBefore, $adjusted['state']['rng_counter']);
        $this->assertSame($eventsBefore, array_slice($adjusted['state']['events'], 0, count($eventsBefore)));
        $this->assertSame(1, $adjusted['state']['teams'][$human]['substitution_windows']);

        $current = $service->control($owner, $career, $session, $this->request($adjusted['revision'], ['action' => 'resume']));
        $this->assertSame('completed', $current['state']['hydration_breaks']['first_half']['status']);
        $current = $service->advance($owner, $career, $session, $this->request($current['revision'], ['minutes' => 60]));
        $this->assertSame('half_time', $current['state']['status']);
        $this->assertSame(45 + $current['state']['first_half_added_time'], $current['minute']);
        $this->assertSame($current['state']['first_half_added_time'], $current['state']['added_minute']);
        $this->assertGreaterThanOrEqual(3, $current['state']['first_half_added_time']);
        $current = $service->control($owner, $career, $session, $this->request($current['revision'], ['action' => 'resume']));
        $current = $service->advance($owner, $career, $session, $this->request($current['revision'], ['minutes' => 60]));
        $this->assertSame('hydration_break', $current['state']['status']);
        $this->assertSame($current['state']['first_half_total'] + 30, $current['minute']);
        $this->assertSame(75, $current['state']['minute']);

        $finished = $service->finish($owner, $career, $session, $this->request($current['revision']));
        $this->assertSame('finished', $finished['status']);
        $this->assertCount(2, $this->events($finished['result'], 'hydration_break_start'));
        $this->assertCount(2, $this->events($finished['result'], 'hydration_break_end'));
        $this->assertSame(
            ReplayHash::of($finished['result']),
            ReplayHash::of((new FootballMatchEngine)->simulate($session->fresh()->input)),
        );
    }

    public function test_competition_snapshot_can_disable_hydration_without_changing_other_careers(): void
    {
        [$firstOwner, $firstCareer] = $this->readyCareer('Regra habilitada');
        $firstEdition = CompetitionEdition::where('career_id', $firstCareer->id)->sole();
        $this->assertTrue($firstEdition->rules['hydration_breaks_enabled']);
        $this->assertSame(Simulation::VERSION, $firstEdition->rules['match_engine_version']);
        $this->assertTrue($firstEdition->rules['dynamic_added_time_enabled']);
        $this->assertSame(AddedTimeRules::DEFAULTS, $firstEdition->rules['added_time_rules']);

        $ruleset = ClubTemplate::firstOrFail()->competition->ruleset;
        $changedAddedTime = array_replace(AddedTimeRules::DEFAULTS, ['goal_celebration_seconds' => [50, 50]]);
        $ruleset->update(['hydration_breaks_enabled' => false, 'added_time_rules' => $changedAddedTime]);
        [$secondOwner, $secondCareer] = $this->readyCareer('Regra desabilitada');
        $secondEdition = CompetitionEdition::where('career_id', $secondCareer->id)->sole();
        $this->assertFalse($secondEdition->rules['hydration_breaks_enabled']);
        $this->assertSame($changedAddedTime, $secondEdition->rules['added_time_rules']);
        $this->assertTrue($firstEdition->fresh()->rules['hydration_breaks_enabled']);
        $this->assertSame(AddedTimeRules::DEFAULTS, $firstEdition->fresh()->rules['added_time_rules']);

        $service = app(InteractiveMatchService::class);
        $match = app(CareerCalendar::class)->nextMatch($secondCareer);
        $session = $service->start($secondOwner, $secondCareer, $match);
        $finished = $service->finish($secondOwner, $secondCareer, $session, $this->request(0));
        $this->assertFalse($session->fresh()->input['rules']['hydration_breaks_enabled']);
        $this->assertSame([], $this->events($finished['result'], 'hydration_break_start'));
        $this->assertSame(0, array_sum(array_column(array_filter(
            $finished['result']['lost_time']['first_half']['contributions'],
            fn (array $item): bool => $item['type'] === 'hydration_break_start',
        ), 'seconds')));
    }

    private function readyCareer(string $name): array
    {
        $this->seed();
        $owner = User::factory()->create();
        $career = app(CreateCareer::class)->execute($owner, [
            'name' => $name, 'coach_name' => 'Auditora', 'club_template_id' => ClubTemplate::firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ]);
        app(SaveSquad::class)->execute($owner, $career, app(SquadSheet::class)->forCareer($career)['settings']);
        app(CareerProgressionService::class)->execute($owner, $career, [
            'action' => CareerProgressionService::ADVANCE_TO_NEXT_MATCH,
            'request_id' => (string) Str::uuid(), 'revision' => $career->fresh()->progression_revision,
        ]);

        return [$owner, $career->fresh()];
    }

    private function request(int $revision, array $extra = []): array
    {
        return $extra + ['request_id' => (string) Str::uuid(), 'revision' => $revision];
    }

    private function events(array $result, string $type): array
    {
        return array_values(array_filter($result['events'], fn (array $event): bool => $event['type'] === $type));
    }
}
