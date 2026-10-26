<?php

namespace Tests\Feature\Matches;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Matches\InteractiveMatchService;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V6\Simulation;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InteractiveMatchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->career = app(CreateCareer::class)->execute($this->owner, [
            'name' => 'Ao vivo', 'coach_name' => 'Ana', 'club_template_id' => ClubTemplate::firstOrFail()->id,
            'request_id' => '00000000-0000-4000-8000-000000000001',
        ]);
        $edition = CompetitionEdition::where('career_id', $this->career->id)->sole();
        $edition->update(['rules' => array_replace($edition->rules, ['hydration_breaks_enabled' => false])]);
        app(SaveSquad::class)->execute($this->owner, $this->career, app(SquadSheet::class)->forCareer($this->career)['settings']);
        app(CareerProgressionService::class)->execute($this->owner, $this->career, [
            'action' => CareerProgressionService::ADVANCE_TO_NEXT_MATCH, 'request_id' => (string) Str::uuid(),
            'revision' => $this->career->fresh()->progression_revision,
        ]);
        $this->career->refresh();
        $this->actingAs($this->owner);
    }

    private function match()
    {
        return app(CareerCalendar::class)->nextMatch($this->career);
    }

    private function request(int $revision, array $extra = []): array
    {
        return $extra + ['request_id' => (string) Str::uuid(), 'revision' => $revision];
    }

    public function test_basic_match_flow_advances_each_minute_freezes_on_pauses_and_consolidates_once(): void
    {
        $service = app(InteractiveMatchService::class);
        $match = $this->match();
        $session = $service->start($this->owner, $this->career, $match);
        $sameSession = $service->start($this->owner, $this->career, $match);

        $this->assertSame($session->id, $sameSession->id);
        $this->assertDatabaseCount('interactive_match_sessions', 1);
        $this->assertSame(0, $session->minute);
        $this->assertTrue($session->paused);
        $this->assertSame(['kickoff'], array_column($session->state['events'], 'type'));

        $initial = $service->response($session);
        try {
            $service->advance($this->owner, $this->career, $session, $this->request(0, ['minutes' => 1]));
            $this->fail('Uma partida pausada avançou.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('match', $exception->errors());
        }
        $this->assertSame($initial, $service->response($session->fresh()));

        $current = $service->control($this->owner, $this->career, $session, $this->request(0, ['action' => 'resume']));
        $this->assertFalse($current['paused']);
        for ($expectedMinute = 1; $expectedMinute <= 10; $expectedMinute++) {
            $current = $this->advanceOneMinute($service, $session, $current, $expectedMinute);
        }

        $current = $service->control($this->owner, $this->career, $session,
            $this->request($current['revision'], ['action' => 'pause']));
        $paused = $current;
        $requestCount = InteractiveMatchSession::findOrFail($session->id)->requests()->count();
        try {
            $service->advance($this->owner, $this->career, $session, $this->request($current['revision'], ['minutes' => 1]));
            $this->fail('A pausa manual não congelou a partida.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('match', $exception->errors());
        }
        $this->assertSame($paused, $service->response($session->fresh()));
        $this->assertSame($requestCount, $session->fresh()->requests()->count());

        $current = $service->control($this->owner, $this->career, $session,
            $this->request($current['revision'], ['action' => 'resume']));
        for ($expectedMinute = 11; $expectedMinute <= 45; $expectedMinute++) {
            $current = $this->advanceOneMinute($service, $session, $current, $expectedMinute);
        }
        while (! $current['paused']) {
            $current = $this->advanceOneMinute($service, $session, $current, $current['minute'] + 1);
        }
        $this->assertTrue($current['paused']);
        $this->assertSame('half_time', $current['state']['status']);
        $this->assertSame(45 + $current['state']['first_half_added_time'], $current['minute']);
        $this->assertNotNull($current['state']['lost_time']['first_half']['announced_minutes']);
        $this->assertCount(1, array_filter($current['state']['events'], fn (array $event): bool => $event['type'] === 'half_time'));

        $halfTime = $current;
        try {
            $service->advance($this->owner, $this->career, $session, $this->request($current['revision'], ['minutes' => 1]));
            $this->fail('O intervalo não bloqueou o avanço.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('match', $exception->errors());
        }
        $this->assertSame($halfTime, $service->response($session->fresh()));

        $current = $service->control($this->owner, $this->career, $session,
            $this->request($current['revision'], ['action' => 'resume']));
        $this->assertSame('playing', $current['state']['status']);
        $lastAdvance = null;
        while ($current['status'] === 'running') {
            $lastAdvance = $this->request($current['revision'], ['minutes' => 1]);
            $current = $this->advanceOneMinute($service, $session, $current, $current['minute'] + 1, $lastAdvance);
        }

        $this->assertSame('finished', $current['status']);
        $this->assertTrue($current['paused']);
        $this->assertSame(90 + $current['state']['added_time'], $current['minute']);
        $this->assertSame($current['minute'], $current['result']['minutes']);
        $this->assertBasicEventConsistency($current['result']);

        $requestCount = $session->fresh()->requests()->count();
        $this->assertSame($current, $service->advance($this->owner, $this->career, $session, $lastAdvance));
        $this->assertSame($requestCount, $session->fresh()->requests()->count());
        $this->assertSame($current['state'], $session->fresh()->state);

        $progression = [
            'action' => CareerProgressionService::PLAY_MATCH,
            'request_id' => (string) Str::uuid(),
            'revision' => $this->career->fresh()->progression_revision,
            'match_id' => $match->id,
        ];
        $operation = app(CareerProgressionService::class)->execute($this->owner, $this->career, $progression);
        $official = OfficialMatchResult::where('career_id', $this->career->id)->where('match_id', $match->id)->sole();
        $this->assertSame(ReplayHash::of($current['result']), ReplayHash::of($official->result));
        $this->assertSame($current['result']['home']['stats']['goals'], $official->home_goals);
        $this->assertSame($current['result']['away']['stats']['goals'], $official->away_goals);
        $resultCount = OfficialMatchResult::where('career_id', $this->career->id)->count();
        $retry = app(CareerProgressionService::class)->execute($this->owner, $this->career, $progression);

        $this->assertSame($operation->id, $retry->id);
        $this->assertSame($resultCount, OfficialMatchResult::where('career_id', $this->career->id)->count());
        $this->assertSame(1, OfficialMatchResult::where('career_id', $this->career->id)->where('match_id', $match->id)->count());
        $this->assertSame('finalized', $session->fresh()->status);
    }

    private function advanceOneMinute(InteractiveMatchService $service, InteractiveMatchSession $session, array $before,
        int $expectedMinute, ?array $request = null): array
    {
        $eventCount = count($before['state']['events']);
        $request ??= $this->request($before['revision'], ['minutes' => 1]);
        $after = $service->advance($this->owner, $this->career, $session, $request);

        $this->assertSame($expectedMinute, $after['minute']);
        $this->assertSame($before['minute'] + 1, $after['minute']);
        foreach (array_slice($after['state']['events'], $eventCount) as $event) {
            $this->assertSame($expectedMinute, $event['tick'] ?? $event['minute'], 'Um evento foi antecipado ou atrasado.');
        }

        return $after;
    }

    private function assertBasicEventConsistency(array $result): void
    {
        $events = $result['events'];
        $this->assertSame(range(1, count($events)), array_column($events, 'sequence'));
        $minutes = array_column($events, 'minute');
        $ticks = array_column($events, 'tick');
        $ordered = $ticks;
        sort($ordered);
        $this->assertSame($ordered, $ticks);
        $this->assertSame($result['minutes'], max($ticks));
        $this->assertCount(1, array_filter($events, fn (array $event): bool => $event['type'] === 'kickoff' && $event['minute'] === 0));
        $this->assertCount(1, array_filter($events, fn (array $event): bool => $event['type'] === 'half_time' && $event['minute'] === 45));
        $this->assertCount(1, array_filter($events, fn (array $event): bool => $event['type'] === 'full_time' && $event['tick'] === $result['minutes']));
        $this->assertCount($result['minutes'], array_filter($events, fn (array $event): bool => $event['type'] === 'possession'));

        foreach (['home', 'away'] as $side) {
            foreach (['goal' => 'goals', 'opportunity' => 'opportunities', 'shot' => 'shots', 'foul' => 'fouls',
                'yellow_card' => 'yellow_cards', 'red_card' => 'red_cards', 'save' => 'saves', 'substitution' => 'substitutions'] as $eventType => $stat) {
                $count = count(array_filter($events, fn (array $event): bool => $event['type'] === $eventType && $event['side'] === $side));
                $this->assertSame($result[$side]['stats'][$stat], $count, "A estatística {$stat} divergiu dos eventos.");
            }
            $this->assertSame($result[$side]['stats']['goals'], array_sum(array_column($result[$side]['players'], 'goals')));
        }
    }

    public function test_match_pauses_at_half_time_restores_rng_and_finalizes_the_official_round_once(): void
    {
        $service = app(InteractiveMatchService::class);
        $match = $this->match();
        $session = $service->start($this->owner, $this->career, $match);
        $this->assertSame(CareerState::PreMatch, $this->career->state);
        $this->assertTrue($session->paused);
        $this->assertSame(Simulation::VERSION, $session->engine_version);

        $resume = $this->request(0, ['action' => 'resume']);
        $running = $service->control($this->owner, $this->career, $session, $resume);
        $this->assertFalse($running['paused']);
        $this->assertSame($running, $service->control($this->owner, $this->career, $session, $resume));
        $this->assertDatabaseCount('interactive_match_requests', 1);

        $halfTime = $service->advance($this->owner, $this->career, $session, $this->request(1, ['minutes' => 45]));
        $this->assertSame(45, $halfTime['minute']);
        if (! $halfTime['paused']) {
            $halfTime = $service->advance($this->owner, $this->career, $session,
                $this->request($halfTime['revision'], ['minutes' => 20]));
        }
        $this->assertTrue($halfTime['paused']);
        $this->assertSame('half_time', $halfTime['state']['status']);
        $rngAtHalfTime = $halfTime['state']['rng_counter'];
        $this->assertSame(45 + $halfTime['state']['first_half_added_time'], $session->fresh()->minute);

        $decision = $service->decide($this->owner, $this->career, $session, $this->request($halfTime['revision'], [
            'type' => 'tactics', 'payload' => ['formation' => '4-2-4', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'heavy'],
        ]));
        $this->assertSame($rngAtHalfTime, $decision['state']['rng_counter']);
        $this->assertSame('4-2-4', $decision['state']['teams'][$session->meta['human_side']]['tactics']['formation']);
        $resumed = $service->control($this->owner, $this->career, $session, $this->request($decision['revision'], ['action' => 'resume']));
        $finished = $service->finish($this->owner, $this->career, $session, $this->request($resumed['revision']));
        $this->assertSame('finished', $finished['status']);
        $this->assertGreaterThanOrEqual(91, $finished['minute']);
        $this->assertSame(ReplayHash::of($finished['result']), ReplayHash::of((new FootballMatchEngine)->simulate($session->fresh()->input)));

        $operation = app(CareerProgressionService::class)->execute($this->owner, $this->career, [
            'action' => CareerProgressionService::PLAY_MATCH, 'request_id' => (string) Str::uuid(),
            'revision' => $this->career->fresh()->progression_revision, 'match_id' => $match->id,
        ]);
        $this->assertSame('completed', $operation->status);
        $this->assertSame('finalized', $session->fresh()->status);
        $this->assertDatabaseCount('official_match_results', 5);
        $official = OfficialMatchResult::where('match_id', $match->id)->sole();
        $this->assertSame(Simulation::VERSION, $official->engine_version);
        $this->assertSame($official->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($official->input)));
    }

    public function test_disconnect_and_failed_block_preserve_the_last_confirmed_rng_state_for_recovery(): void
    {
        $service = app(InteractiveMatchService::class);
        $session = $service->start($this->owner, $this->career, $this->match());
        $current = $service->control($this->owner, $this->career, $session, $this->request(0, ['action' => 'resume']));
        $current = $service->advance($this->owner, $this->career, $session, $this->request($current['revision'], ['minutes' => 35]));
        $at35 = $session->fresh();
        $confirmed = $service->response($at35);
        $requestCount = $at35->requests()->count();
        $updatedAt = $at35->updated_at->toJSON();

        $this->post('/logout');
        $this->travel(12)->hours();
        $disconnected = $session->fresh();
        $this->assertSame($confirmed, $service->response($disconnected));
        $this->assertSame($requestCount, $disconnected->requests()->count());
        $this->assertSame($updatedAt, $disconnected->updated_at->toJSON());
        $this->travelBack();
        $this->actingAs($this->owner);

        $current = $service->advance($this->owner, $this->career, $session,
            $this->request($current['revision'], ['minutes' => 20]));
        $this->assertSame('half_time', $current['state']['status']);
        $current = $service->control($this->owner, $this->career, $session,
            $this->request($current['revision'], ['action' => 'resume']));
        $current = $service->advance($this->owner, $this->career, $session,
            $this->request($current['revision'], ['minutes' => 20]));
        $this->assertSame($current['state']['first_half_total'] + 20, $current['minute']);

        $at65 = $service->response($session->fresh());
        $requestCount = $session->fresh()->requests()->count();
        $failedBlock = $this->request($current['revision'], ['minutes' => 5]);
        $event = 'eloquent.saving: '.InteractiveMatchSession::class;
        $failedMinute = $current['minute'] + 5;
        Event::listen($event, fn (InteractiveMatchSession $saving) => $saving->minute === $failedMinute
            ? throw new RuntimeException('Interrupted incremental block') : null);
        try {
            $service->advance($this->owner, $this->career, $session, $failedBlock);
            $this->fail('A falha injetada deveria interromper o bloco.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted incremental block', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame($at65, $service->response($session->fresh()));
        $this->assertSame($requestCount, $session->fresh()->requests()->count());
        $this->assertSame($at65['state']['rng_counter'], $session->fresh()->state['rng_counter']);

        $recovered = $service->advance($this->owner, $this->career, $session, $failedBlock);
        $this->assertSame($failedMinute, $recovered['minute']);
        $this->assertGreaterThan($at65['state']['rng_counter'], $recovered['state']['rng_counter']);
        $finished = $service->finish($this->owner, $this->career, $session,
            $this->request($recovered['revision']));
        $this->assertSame(ReplayHash::of($finished['result']), ReplayHash::of((new FootballMatchEngine)->simulate($session->fresh()->input)));
    }

    public function test_finish_at_minute_60_matches_incremental_play_after_the_same_human_decisions(): void
    {
        $service = app(InteractiveMatchService::class);
        $session = $service->start($this->owner, $this->career, $this->match());
        $current = $service->control($this->owner, $this->career, $session, $this->request(0, ['action' => 'resume']));
        $current = $service->advance($this->owner, $this->career, $session,
            $this->request($current['revision'], ['minutes' => 45]));
        if (! $current['paused']) {
            $current = $service->advance($this->owner, $this->career, $session,
                $this->request($current['revision'], ['minutes' => 20]));
        }
        $current = $service->control($this->owner, $this->career, $session,
            $this->request($current['revision'], ['action' => 'resume']));
        $current = $service->advance($this->owner, $this->career, $session,
            $this->request($current['revision'], ['minutes' => 15]));
        $current = $service->control($this->owner, $this->career, $session,
            $this->request($current['revision'], ['action' => 'pause']));
        $current = $service->decide($this->owner, $this->career, $session, $this->request($current['revision'], [
            'type' => 'tactics',
            'payload' => ['formation' => '4-3-3', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'heavy'],
        ]));

        $this->assertSame(60, $current['state']['minute']);
        $this->assertSame($current['state']['first_half_total'] + 15, $current['minute']);
        $this->assertTrue($current['paused']);
        $confirmedEvents = $current['state']['events'];
        $incremental = new Simulation($session->fresh()->input, $current['state']);
        while (! in_array($incremental->state()['status'], ['finished', 'abandoned'], true)) {
            $incremental->advance(1);
        }
        $expected = $incremental->result();

        $finishRequest = $this->request($current['revision']);
        $finished = $service->finish($this->owner, $this->career, $session, $finishRequest);
        $requestCount = $session->fresh()->requests()->count();

        $this->assertSame($expected, $finished['result']);
        $this->assertSame($confirmedEvents, array_slice($finished['result']['events'], 0, count($confirmedEvents)));
        $this->assertCount(1, array_filter(
            $finished['result']['decisions'],
            fn (array $decision): bool => $decision['actor'] === 'human',
        ));
        $this->assertSame(range(1, count($finished['result']['events'])), array_column($finished['result']['events'], 'sequence'));
        $this->assertCount(1, array_filter($finished['result']['events'], fn (array $event): bool => $event['type'] === 'full_time'));
        $this->assertSame($finished, $service->finish($this->owner, $this->career, $session, $finishRequest));
        $this->assertSame($requestCount, $session->fresh()->requests()->count());
        $this->assertSame(
            ReplayHash::of($finished['result']),
            ReplayHash::of((new FootballMatchEngine)->simulate($session->fresh()->input)),
        );
    }

    public function test_stale_tabs_conflicting_idempotency_and_other_users_cannot_mutate_a_session(): void
    {
        $service = app(InteractiveMatchService::class);
        $session = $service->start($this->owner, $this->career, $this->match());
        $payload = $this->request(0, ['action' => 'resume']);
        $service->control($this->owner, $this->career, $session, $payload);

        try {
            $service->control($this->owner, $this->career, $session, $this->request(0, ['action' => 'pause']));
            $this->fail('Uma revisão obsoleta foi aceita.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
        try {
            $service->control($this->owner, $this->career, $session, array_replace($payload, ['action' => 'pause']));
            $this->fail('Uma chave idempotente conflitante foi aceita.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_id', $exception->errors());
        }

        $other = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        $service->find($other, $this->career, $this->match());
    }

    public function test_official_result_renders_a_saved_position_change(): void
    {
        $service = app(InteractiveMatchService::class);
        $match = $this->match();
        $session = $service->start($this->owner, $this->career, $match);
        $active = array_values($session->state['teams'][$session->meta['human_side']]['active']);
        [$active[5], $active[8]] = [$active[8], $active[5]];
        $service->decide($this->owner, $this->career, $session, $this->request(0, [
            'type' => 'positions', 'payload' => ['active' => $active],
        ]));
        $service->finish($this->owner, $this->career, $session, $this->request(1));
        app(CareerProgressionService::class)->execute($this->owner, $this->career, [
            'action' => CareerProgressionService::PLAY_MATCH, 'request_id' => (string) Str::uuid(),
            'revision' => $this->career->fresh()->progression_revision, 'match_id' => $match->id,
        ]);
        $official = OfficialMatchResult::where('match_id', $match->id)->sole();

        $this->get(route('careers.results.show', [$this->career, $official]))
            ->assertOk()
            ->assertSee('Troca de posições');
    }

    public function test_live_routes_render_all_controls_and_do_not_advance_without_an_explicit_request(): void
    {
        $match = $this->match();
        $token = 'interactive-test-token';
        $this->withSession(['_token' => $token])->post(route('careers.matches.live.start', [$this->career, $match]), ['_token' => $token])
            ->assertRedirect(route('careers.matches.live.show', [$this->career, $match]));
        $session = InteractiveMatchSession::sole();
        $response = $this->get(route('careers.matches.live.show', [$this->career, $match]));
        $response->assertOk()
            ->assertSee('Central da partida')->assertSee('Simular até o final')->assertSee('Ataque total')
            ->assertSee('Pelas laterais')->assertSee('Muito pesada')->assertSee('Banco de reservas')
            ->assertSee('data-live-finalize hidden', false)->assertDontSee("status !== 'finished')>");
        foreach (['0,5×', '0,75×', '1×', '1,25×', '1,5×', '1,75×', '2×'] as $speed) {
            $response->assertSee($speed);
        }
        $revision = $this->career->fresh()->progression_revision;
        $this->post('/logout', ['_token' => $token]);
        $this->get(route('login'))->assertOk();
        $this->assertSame(0, $session->fresh()->minute);
        $this->assertSame($revision, $this->career->fresh()->progression_revision);
    }
}
