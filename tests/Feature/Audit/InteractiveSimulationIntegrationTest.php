<?php

namespace Tests\Feature\Audit;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Competitions\StandingsService;
use App\Application\Matches\InteractiveMatchService;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Engine\V5\Simulation;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InteractiveSimulationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_interactive_result_and_ai_games_close_a_reconciled_round_once(): void
    {
        $this->seed();
        $owner = User::factory()->create();
        $career = app(CreateCareer::class)->execute($owner, [
            'name' => 'Integração esportiva', 'coach_name' => 'Auditora',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid(),
        ]);
        $this->actingAs($owner);
        $initialBalances = $career->clubs()->pluck('balance_cents', 'id');
        $progression = app(CareerProgressionService::class);
        $progression->execute($owner, $career, $this->payload($career, CareerProgressionService::ADVANCE_TO_NEXT_MATCH));
        app(SaveSquad::class)->execute($owner, $career, app(SquadSheet::class)->forCareer($career)['settings']);
        $career->refresh();
        $match = app(CareerCalendar::class)->nextMatch($career);
        $date = $career->current_date->toDateString();

        $live = app(InteractiveMatchService::class);
        $session = $live->start($owner, $career, $match);
        $finished = $live->finish($owner, $career, $session, [
            'request_id' => (string) Str::uuid(), 'revision' => 0,
        ]);
        $this->assertSame('finished', $finished['status']);
        $this->assertSame(Simulation::VERSION, $finished['result']['engine_version']);
        $this->assertSame($date, $career->fresh()->current_date->toDateString());
        $this->travel(30)->days();
        $this->assertSame($date, $career->fresh()->current_date->toDateString());
        $this->assertDatabaseCount('official_match_results', 0);
        $this->travelBack();

        $play = $this->payload($career, CareerProgressionService::PLAY_MATCH, ['match_id' => $match->id]);
        $operation = $progression->execute($owner, $career, $play);
        $this->assertTrue($operation->is($progression->execute($owner, $career, $play)));
        $this->assertSame('finalized', $session->fresh()->status);
        $this->assertSame($date, $career->fresh()->current_date->toDateString());
        $this->assertDatabaseCount('official_match_results', 5);
        $this->assertDatabaseCount('match_attendances', 5);
        $this->assertDatabaseCount('career_financial_transactions', 10);
        $this->assertSame(5, $career->matches()->where('round', 1)->where('status', 'played')->count());

        $edition = CompetitionEdition::where('career_id', $career->id)->sole();
        $standings = app(StandingsService::class)->standings($edition);
        $scorers = app(StandingsService::class)->scorers($edition);
        $this->assertSame(10, array_sum(array_column($standings, 'played')));
        $this->assertSame(array_sum(array_column($standings, 'goals_for')), array_sum(array_column($standings, 'goals_against')));
        $this->assertSame(array_sum(array_column($standings, 'goals_for')), array_sum(array_column($scorers, 'goals')));
        foreach ($standings as $row) {
            $this->assertSame($row['wins'] * $edition->rules['points_for_win']
                + $row['draws'] * $edition->rules['points_for_draw']
                + $row['losses'] * $edition->rules['points_for_loss'], $row['points']);
            $this->assertSame($row['goals_for'] - $row['goals_against'], $row['goal_difference']);
        }

        $official = OfficialMatchResult::where('career_id', $career->id)->get();
        $this->assertSame(5, $official->where('engine_version', Simulation::VERSION)->count());
        foreach ($official as $result) {
            $this->assertSame($result->home_goals, $result->result['home']['stats']['goals']);
            $this->assertSame($result->away_goals, $result->result['away']['stats']['goals']);
            $this->assertSame($result->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($result->input)));
            $this->assertSame($result->attendance->realized, $result->result['attendance']['present']);
            $this->assertLessThanOrEqual($result->attendance->capacity, $result->attendance->realized);

            $ledger = CareerFinancialTransaction::where('match_id', $result->match_id)->get()->keyBy('kind');
            $this->assertCount(2, $ledger);
            $this->assertSame($result->attendance->realized * $result->attendance->ticket_price_cents,
                $ledger['ticket_revenue']->amount_cents);
            $this->assertSame(-($result->attendance->snapshot['input']['fixed_cost_cents']
                + $result->attendance->realized * $result->attendance->snapshot['input']['variable_cost_cents']),
                $ledger['match_operating_cost']->amount_cents);

            foreach (['home', 'away'] as $side) {
                foreach ($result->result[$side]['players'] as $player) {
                    $persisted = $career->players()->findOrFail($player['player_id']);
                    $this->assertSame($player['final_fitness'], $persisted->fitness);
                }
            }
        }
        foreach ($career->clubs as $club) {
            $movement = CareerFinancialTransaction::where('career_id', $career->id)->where('career_club_id', $club->id)->sum('amount_cents');
            $this->assertSame($initialBalances[$club->id] + $movement, $club->fresh()->balance_cents);
        }

        $this->assertDatabaseCount('official_match_results', 5);
        $this->assertDatabaseCount('match_attendances', 5);
        $this->assertDatabaseCount('career_financial_transactions', 10);
    }

    private function payload(Career $career, string $action, array $extra = []): array
    {
        return $extra + [
            'action' => $action, 'request_id' => (string) Str::uuid(),
            'revision' => $career->fresh()->progression_revision,
        ];
    }
}
