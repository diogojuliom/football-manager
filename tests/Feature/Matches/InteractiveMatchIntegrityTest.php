<?php

namespace Tests\Feature\Matches;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Matches\InteractiveMatchService;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InteractiveMatchIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        [$this->owner, $this->career] = $this->career('Integrity owner');
        $this->actingAs($this->owner);
    }

    public function test_start_advance_decision_and_finish_are_idempotent(): void
    {
        $service = app(InteractiveMatchService::class);
        $match = app(CareerCalendar::class)->nextMatch($this->career);
        $session = $service->start($this->owner, $this->career, $match);
        $this->assertTrue($session->is($service->start($this->owner, $this->career, $match)));
        $this->assertDatabaseCount('interactive_match_sessions', 1);

        $resumeRequest = $this->request(0, ['action' => 'resume']);
        $running = $service->control($this->owner, $this->career, $session, $resumeRequest);
        $this->assertCanonicalSame($running, $service->control($this->owner, $this->career, $session, $resumeRequest));

        $advanceRequest = $this->request($running['revision'], ['minutes' => 1]);
        $advanced = $service->advance($this->owner, $this->career, $session, $advanceRequest);
        $this->assertCanonicalSame($advanced, $service->advance($this->owner, $this->career, $session, $advanceRequest));
        $this->assertSame(1, $advanced['minute']);

        $pauseRequest = $this->request($advanced['revision'], ['action' => 'pause']);
        $paused = $service->control($this->owner, $this->career, $session, $pauseRequest);
        $this->assertCanonicalSame($paused, $service->control($this->owner, $this->career, $session, $pauseRequest));
        $decisionRequest = $this->request($paused['revision'], [
            'type' => 'tactics',
            'payload' => ['formation' => '4-3-3', 'style' => 'counter', 'attack_direction' => 'center', 'marking' => 'heavy'],
        ]);
        $decided = $service->decide($this->owner, $this->career, $session, $decisionRequest);
        $this->assertCanonicalSame($decided, $service->decide($this->owner, $this->career, $session, $decisionRequest));
        $this->assertCount(1, array_filter($decided['state']['decisions'], fn (array $decision): bool => $decision['actor'] === 'human'));

        $finishRequest = $this->request($decided['revision']);
        $finished = $service->finish($this->owner, $this->career, $session, $finishRequest);
        $this->assertCanonicalSame($finished, $service->finish($this->owner, $this->career, $session, $finishRequest));
        $this->assertSame('finished', $finished['status']);
        $this->assertCount(1, array_filter($finished['state']['events'], fn (array $event): bool => $event['type'] === 'full_time'));
        $this->assertDatabaseCount('interactive_match_requests', 5);
    }

    public function test_stale_revision_invalid_player_and_arbitrary_minute_do_not_corrupt_official_state(): void
    {
        $service = app(InteractiveMatchService::class);
        $match = app(CareerCalendar::class)->nextMatch($this->career);
        $session = $service->start($this->owner, $this->career, $match);
        $running = $service->control($this->owner, $this->career, $session, $this->request(0, ['action' => 'resume']));
        $before = $service->response($session->fresh());
        $requestCount = $session->fresh()->requests()->count();

        try {
            $service->advance($this->owner, $this->career, $session, $this->request(0, ['minutes' => 1]));
            $this->fail('A stale revision was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
        $this->assertCanonicalSame($before, $service->response($session->fresh()));
        $this->assertSame($requestCount, $session->fresh()->requests()->count());

        $paused = $service->control($this->owner, $this->career, $session,
            $this->request($running['revision'], ['action' => 'pause']));
        $beforeInvalidPlayer = $service->response($session->fresh());
        $requestCount = $session->fresh()->requests()->count();
        $out = $paused['state']['teams'][$session->meta['human_side']]['active'][1];
        try {
            $service->decide($this->owner, $this->career, $session, $this->request($paused['revision'], [
                'type' => 'substitution', 'payload' => ['out' => $out, 'in' => 'invalid-player'],
            ]));
            $this->fail('An invalid player was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertCanonicalSame($beforeInvalidPlayer, $service->response($session->fresh()));
            $this->assertSame($requestCount, $session->fresh()->requests()->count());
        }

        $resumed = $service->control($this->owner, $this->career, $session,
            $this->request($paused['revision'], ['action' => 'resume']));
        $response = $this->postJson(route('careers.matches.live.advance', [$this->career, $match]), [
            'request_id' => (string) Str::uuid(), 'revision' => $resumed['revision'], 'minutes' => 1, 'minute' => 89,
        ])->assertOk()->json();
        $this->assertSame(1, $response['minute']);
        $this->assertSame(1, $response['state']['minute']);
    }

    public function test_another_user_cannot_read_or_mutate_the_match(): void
    {
        $service = app(InteractiveMatchService::class);
        $match = app(CareerCalendar::class)->nextMatch($this->career);
        $session = $service->start($this->owner, $this->career, $match);
        $before = $service->response($session);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('careers.matches.live.show', [$this->career, $match]))
            ->assertForbidden();
        $this->postJson(route('careers.matches.live.advance', [$this->career, $match]), $this->request(0, ['minutes' => 1]))
            ->assertForbidden();

        try {
            $service->find($intruder, $this->career, $match);
            $this->fail('Another user accessed the match service.');
        } catch (AuthorizationException) {
            $this->assertCanonicalSame($before, $service->response($session->fresh()));
            $this->assertDatabaseCount('interactive_match_requests', 0);
        }
    }

    public function test_persistence_and_finish_failures_roll_back_and_can_be_retried(): void
    {
        $service = app(InteractiveMatchService::class);
        $session = $service->start($this->owner, $this->career, app(CareerCalendar::class)->nextMatch($this->career));
        $running = $service->control($this->owner, $this->career, $session, $this->request(0, ['action' => 'resume']));
        $advanceRequest = $this->request($running['revision'], ['minutes' => 1]);
        $beforeAdvance = $service->response($session->fresh());
        $this->failNextSessionSave(fn (InteractiveMatchSession $saving): bool => $saving->minute === 1, function () use ($service, $session, $advanceRequest): void {
            $service->advance($this->owner, $this->career, $session, $advanceRequest);
        });
        $this->assertCanonicalSame($beforeAdvance, $service->response($session->fresh()));
        $this->assertSame(1, $session->fresh()->requests()->count());
        $advanced = $service->advance($this->owner, $this->career, $session, $advanceRequest);
        $this->assertSame(1, $advanced['minute']);

        $finishRequest = $this->request($advanced['revision']);
        $beforeFinish = $service->response($session->fresh());
        $requestCount = $session->fresh()->requests()->count();
        $this->failNextSessionSave(fn (InteractiveMatchSession $saving): bool => $saving->status === 'finished', function () use ($service, $session, $finishRequest): void {
            $service->finish($this->owner, $this->career, $session, $finishRequest);
        });
        $this->assertCanonicalSame($beforeFinish, $service->response($session->fresh()));
        $this->assertSame($requestCount, $session->fresh()->requests()->count());
        $finished = $service->finish($this->owner, $this->career, $session, $finishRequest);
        $this->assertSame('finished', $finished['status']);
        $this->assertCount(1, array_filter($finished['result']['events'], fn (array $event): bool => $event['type'] === 'full_time'));
    }

    private function career(string $name): array
    {
        $user = User::factory()->create();
        $career = app(CreateCareer::class)->execute($user, [
            'name' => $name, 'coach_name' => 'Auditor', 'club_template_id' => ClubTemplate::firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ]);
        app(SaveSquad::class)->execute($user, $career, app(SquadSheet::class)->forCareer($career)['settings']);
        app(CareerProgressionService::class)->execute($user, $career, [
            'action' => CareerProgressionService::ADVANCE_TO_NEXT_MATCH,
            'request_id' => (string) Str::uuid(), 'revision' => $career->fresh()->progression_revision,
        ]);

        return [$user, $career->fresh()];
    }

    private function request(int $revision, array $extra = []): array
    {
        return $extra + ['request_id' => (string) Str::uuid(), 'revision' => $revision];
    }

    private function failNextSessionSave(callable $condition, callable $operation): void
    {
        $event = 'eloquent.saving: '.InteractiveMatchSession::class;
        Event::listen($event, fn (InteractiveMatchSession $saving) => $condition($saving)
            ? throw new RuntimeException('Injected persistence failure') : null);
        try {
            $operation();
            $this->fail('The injected persistence failure did not happen.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
    }

    private function assertCanonicalSame(array $expected, array $actual): void
    {
        $this->assertSame(ReplayHash::of($expected), ReplayHash::of($actual));
    }
}
