<?php

namespace Tests\Feature\Careers;

use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Careers\SeasonTransitionService;
use App\Application\Competitions\StandingsService;
use App\Application\Matches\InteractiveMatchService;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerOperation;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Career\Models\StadiumProject;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V4\Simulation;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ConsecutiveSeasonsTest extends TestCase
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
            'name' => 'Três temporadas', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid(),
        ]);
        $this->actingAs($this->owner);
    }

    private function payload(string $action, array $extra = []): array
    {
        return $extra + ['action' => $action, 'request_id' => (string) Str::uuid(),
            'revision' => $this->career->fresh()->progression_revision];
    }

    private function progress(string $action, array $extra = []): CareerOperation
    {
        return app(CareerProgressionService::class)->execute($this->owner, $this->career, $this->payload($action, $extra));
    }

    private function saveAvailableLineup(): void
    {
        $this->career->refresh()->unsetRelation('controlledClub');
        $current = $this->career->controlledClub->club->lineup;
        $formation = $current?->formation === '4-4-2' ? '4-3-3' : '4-4-2';
        $settings = app(SquadSheet::class)->forCareer($this->career, $formation)['settings'];
        app(SaveSquad::class)->execute($this->owner, $this->career, $settings);
    }

    private function finishCurrentSeason(): void
    {
        for ($round = 1; $round <= 18; $round++) {
            $this->progress('ADVANCE_TO_NEXT_MATCH');
            $this->saveAvailableLineup();
            $match = app(\App\Application\Careers\CareerCalendar::class)->nextMatch($this->career->fresh());
            $this->assertSame($round, $match->round);
            $date = $this->career->fresh()->current_date->toDateString();
            $session = app(InteractiveMatchService::class)->start($this->owner, $this->career, $match);
            $finished = app(InteractiveMatchService::class)->finish($this->owner, $this->career, $session, [
                'request_id' => (string) Str::uuid(), 'revision' => 0,
            ]);
            $this->assertSame('finished', $finished['status']);
            $this->assertSame(Simulation::VERSION, $finished['result']['engine_version']);
            $this->assertSame($date, $this->career->fresh()->current_date->toDateString());
            $this->progress('PLAY_MATCH', ['match_id' => $match->id]);
            $this->assertSame('finalized', $session->fresh()->status);
        }
        $this->progress('ADVANCE_TO_NEXT_EVENT');
        $this->assertSame(CareerState::AwaitingSeason, $this->career->fresh()->state);
    }

    public function test_three_complete_seasons_preserve_history_administration_and_unique_schedules(): void
    {
        $club = $this->career->controlledClub->club;
        $clubId = $club->id;
        $stadiumId = $club->stadium->id;
        $capacity = $club->stadium->capacity;
        $supportId = $club->support->id;
        $initialPlayerIds = $club->players()->orderBy('id')->pluck('id')->all();
        $initialAges = $club->players()->orderBy('id')->pluck('age', 'id')->all();
        $initialBalances = $this->career->clubs()->pluck('balance_cents', 'id');
        $summaries = [];

        for ($number = 1; $number <= 3; $number++) {
            $this->assertSame($number, $this->career->fresh()->current_season_number);
            $this->finishCurrentSeason();
            $season = $this->career->seasons()->where('number', $number)->sole();
            $edition = CompetitionEdition::where('career_id', $this->career->id)->where('career_season_id', $season->id)->sole();
            $summaries[$number] = $season->summary;
            $matches = $this->career->matches()->where('competition_edition_id', $edition->id);
            $this->assertSame(90, (clone $matches)->count());
            $this->assertSame(90, (clone $matches)->where('status', 'played')->count());
            $this->assertSame(90, (clone $matches)->whereHas('result')->count());
            foreach ($this->career->clubs as $participant) {
                $this->assertSame(9, (clone $matches)->where('home_club_id', $participant->id)->count());
                $this->assertSame(9, (clone $matches)->where('away_club_id', $participant->id)->count());
            }
            $this->assertSame(90, DB::table('matches')->where('competition_edition_id', $edition->id)
                ->distinct()->count(DB::raw("CAST(home_club_id AS TEXT) || ':' || CAST(away_club_id AS TEXT)")));

            if ($number < 3) {
                $balance = $club->fresh()->balance_cents;
                $engagement = $club->support->fresh()->engagement;
                $endedOn = $season->ended_on;
                $this->travel(180)->days();
                $this->get(route('careers.show', $this->career))->assertOk()->assertSee('Iniciar temporada '.($number + 1));
                $this->assertTrue($endedOn->eq($this->career->fresh()->current_date));
                $operation = $this->progress('START_NEXT_SEASON');
                $this->travelBack();
                $this->assertSame($endedOn->addDays(config('careers.offseason_days'))->toDateString(), $operation->result['current_date']);
                $this->assertSame($clubId, $this->career->controlledClub->club->id);
                $this->assertSame($stadiumId, $club->stadium->fresh()->id);
                $this->assertSame($capacity, $club->stadium->fresh()->capacity);
                $this->assertSame($supportId, $club->support->fresh()->id);
                $this->assertSame($engagement, $club->support->fresh()->engagement);
                $this->assertSame($balance, $club->fresh()->balance_cents);
                $this->assertSame($initialPlayerIds, $club->players()->orderBy('id')->pluck('id')->all());
            }
        }

        $this->assertSame(3, $this->career->seasons()->count());
        $this->assertSame(3, CompetitionEdition::where('career_id', $this->career->id)->count());
        $this->assertSame(270, $this->career->matches()->count());
        $this->assertSame(270, OfficialMatchResult::where('career_id', $this->career->id)->count());
        $this->assertSame(540, CareerFinancialTransaction::where('career_id', $this->career->id)->whereNotNull('match_id')->count());
        $this->assertSame(270, OfficialMatchResult::where('career_id', $this->career->id)->distinct()->count('match_id'));
        $this->assertSame(540, CareerFinancialTransaction::where('career_id', $this->career->id)->whereNotNull('match_id')->distinct()->count('source_key'));
        $this->assertSame(54, OfficialMatchResult::where('career_id', $this->career->id)->where('engine_version', Simulation::VERSION)->count());
        $this->assertSame(216, OfficialMatchResult::where('career_id', $this->career->id)->where('engine_version', FootballMatchEngine::VERSION)->count());
        $this->assertSame(54, InteractiveMatchSession::where('career_id', $this->career->id)->where('status', 'finalized')->count());
        foreach ($summaries as $number => $summary) {
            $season = $this->career->seasons()->where('number', $number)->sole();
            $this->assertSame($summary, $season->summary);
            $edition = CompetitionEdition::where('career_id', $this->career->id)->where('career_season_id', $season->id)->sole();
            $standings = app(StandingsService::class)->standings($edition);
            $scorers = app(StandingsService::class)->scorers($edition);
            $this->assertSame(ReplayHash::of($standings), ReplayHash::of($summary[0]['standings']));
            $this->assertSame(ReplayHash::of($scorers), ReplayHash::of($summary[0]['scorers']));
            $this->assertSame(180, array_sum(array_column($standings, 'played')));
            $this->assertSame(array_sum(array_column($standings, 'goals_for')), array_sum(array_column($scorers, 'goals')));
        }
        foreach ($this->career->clubs as $participant) {
            $movement = CareerFinancialTransaction::where('career_id', $this->career->id)
                ->where('career_club_id', $participant->id)->sum('amount_cents');
            $this->assertSame($initialBalances[$participant->id] + $movement, $participant->fresh()->balance_cents);
        }
        foreach ($initialAges as $id => $age) {
            $this->assertSame($age + 2, $this->career->players()->findOrFail($id)->age);
        }
        $this->get(route('careers.show', $this->career))->assertOk()->assertSee('Histórico de temporadas')
            ->assertSee('Temporada 1')->assertSee('Temporada 2')->assertSee('Temporada 3');
        $this->get(route('careers.show', ['career' => $this->career, 'season' => 1]))->assertOk()
            ->assertSee('Classificação')->assertSee('Temporada 1')->assertSee($summaries[1][0]['standings'][0]['name']);
        $this->get(route('careers.show', ['career' => $this->career, 'season' => 99]))->assertNotFound();
        foreach ([Simulation::VERSION, FootballMatchEngine::VERSION] as $version) {
            $historical = OfficialMatchResult::where('career_id', $this->career->id)->where('engine_version', $version)->firstOrFail();
            $this->assertSame($historical->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($historical->input)));
            $this->get(route('careers.results.show', [$this->career, $historical]))->assertOk()->assertSee('Motor '.$version);
        }
    }

    public function test_interrupted_transition_rolls_back_and_resume_is_idempotent_and_isolated(): void
    {
        config()->set('stadium_projects.expansion.duration_days', 30);
        $stadium = $this->career->controlledClub->club->stadium;
        $capacity = $stadium->capacity;
        $this->post(route('careers.stadium.projects.store', $this->career), [
            'type' => 'expansion', 'revision' => $this->career->fresh()->progression_revision,
        ])->assertSessionHasNoErrors();
        $season = $this->career->seasons()->sole();
        $edition = CompetitionEdition::where('career_id', $this->career->id)->sole();
        $season->update(['status' => 'completed', 'ended_on' => $this->career->current_date,
            'summary' => [['standings' => [['name' => 'Aurora']], 'scorers' => [], 'champion_club_id' => $this->career->clubs()->value('id')]]]);
        $edition->update(['status' => 'completed']);
        $this->career->update(['state' => CareerState::AwaitingSeason]);
        $other = app(CreateCareer::class)->execute($this->owner, [
            'name' => 'Isolada', 'coach_name' => 'Bia', 'club_template_id' => ClubTemplate::orderBy('id')->skip(1)->firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ]);
        $otherDate = $other->current_date->toDateString();

        $real = app(SeasonTransitionService::class);
        $this->mock(SeasonTransitionService::class)->shouldReceive('execute')->once()->andReturnUsing(function ($career) use ($real): void {
            $real->execute($career);
            throw new RuntimeException('Falha após criar o calendário.');
        });
        $progression = app(CareerProgressionService::class);
        $operation = $progression->authorizeOperation($this->owner, $this->career, $this->payload('START_NEXT_SEASON'));
        try {
            $progression->resume($this->owner, $this->career, $operation);
            $this->fail('A falha simulada deveria interromper a transição.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha após criar o calendário.', $exception->getMessage());
        }
        $this->assertSame('pending', $operation->fresh()->status);
        $this->assertSame(CareerState::Processing, $this->career->fresh()->state);
        $this->assertSame(1, $this->career->seasons()->count());
        $this->assertSame(90, $this->career->matches()->count());
        $this->assertSame('active', StadiumProject::where('career_id', $this->career->id)->sole()->status);
        $this->assertSame($capacity, $stadium->fresh()->capacity);

        $this->app->instance(SeasonTransitionService::class, $real);
        $operation = app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame(2, $this->career->seasons()->count());
        $this->assertSame(180, $this->career->matches()->count());
        $this->assertSame(2, $this->career->fresh()->current_season_number);
        $this->assertSame('completed', StadiumProject::where('career_id', $this->career->id)->sole()->status);
        $this->assertSame($capacity + 5000, $stadium->fresh()->capacity);
        $this->post(route('careers.progression.store', $this->career), $this->payload('START_NEXT_SEASON'))->assertSessionHasErrors('progression');
        $this->assertSame(1, $other->fresh()->current_season_number);
        $this->assertSame($otherDate, $other->fresh()->current_date->toDateString());
        $this->assertSame(90, $other->matches()->count());
    }

    public function test_retirement_and_academy_replacement_are_deterministic_and_keep_valid_squad(): void
    {
        $club = $this->career->controlledClub->club;
        $veteran = $club->players()->where('primary_position', 'GK')->firstOrFail();
        $veteran->update(['age' => config('careers.retirement_age') - 1]);
        $season = $this->career->seasons()->sole();
        $season->update(['status' => 'completed', 'ended_on' => $this->career->current_date,
            'summary' => [['standings' => [['name' => $club->name]], 'scorers' => [], 'champion_club_id' => $club->id]]]);
        CompetitionEdition::where('career_id', $this->career->id)->update(['status' => 'completed']);
        $this->career->update(['state' => CareerState::AwaitingSeason]);

        $this->progress('START_NEXT_SEASON');
        $replacement = $club->allPlayers()->where('origin_key', 'like', 'academy:%')->sole();
        $this->assertSame(18, $replacement->age);
        $this->assertSame(2, $replacement->joined_season_number);
        $this->assertSame($this->career->fresh()->current_date->toDateString(), $veteran->fresh()->retired_on->toDateString());
        $this->assertSame(config('careers.minimum_squad_size'), $club->players()->count());
        $this->assertGreaterThanOrEqual(config('careers.minimum_goalkeepers'), $club->players()->where('primary_position', 'GK')->count());
        $this->assertSame(1, $this->career->seasons()->where('number', 2)->sole()->transition_summary['retired_players']);
        $this->assertSame(1, $this->career->seasons()->where('number', 2)->sole()->transition_summary['generated_players']);
    }
}
