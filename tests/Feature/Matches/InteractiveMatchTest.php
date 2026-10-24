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
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V4\Simulation;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            'request_id' => (string) Str::uuid(),
        ]);
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
        $this->assertTrue($halfTime['paused']);
        $this->assertSame('half_time', $halfTime['state']['status']);
        $rngAtHalfTime = $halfTime['state']['rng_counter'];
        $this->assertSame(45, $session->fresh()->minute);

        $decision = $service->decide($this->owner, $this->career, $session, $this->request(2, [
            'type' => 'tactics', 'payload' => ['formation' => '4-2-4', 'style' => 'total_attack', 'attack_direction' => 'wings', 'marking' => 'heavy'],
        ]));
        $this->assertSame($rngAtHalfTime, $decision['state']['rng_counter']);
        $this->assertSame('4-2-4', $decision['state']['teams'][$session->meta['human_side']]['tactics']['formation']);
        $service->control($this->owner, $this->career, $session, $this->request(3, ['action' => 'resume']));
        $finished = $service->finish($this->owner, $this->career, $session, $this->request(4));
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
