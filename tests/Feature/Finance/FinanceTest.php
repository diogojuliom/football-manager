<?php

namespace Tests\Feature\Finance;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\Finance\Models\MatchTicketPolicy;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class FinanceTest extends TestCase
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
            'name' => 'Finanças', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::where('code', 'atletico-ribeira')->firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ]);
        $this->actingAs($this->owner);
    }

    private function payload(string $action, array $extra = []): array
    {
        return $extra + ['action' => $action, 'request_id' => (string) Str::uuid(),
            'revision' => $this->career->fresh()->progression_revision];
    }

    private function prepareGame(): void
    {
        app(CareerProgressionService::class)->execute($this->owner, $this->career, $this->payload('ADVANCE_TO_NEXT_MATCH'));
        app(SaveSquad::class)->execute($this->owner, $this->career, app(SquadSheet::class)->forCareer($this->career)['settings']);
    }

    public function test_default_price_is_persisted_and_applies_only_without_a_match_override(): void
    {
        $match = app(CareerCalendar::class)->nextMatch($this->career);
        $this->assertSame(3000, app(MatchAttendanceService::class)->forecast($this->career, $match->homeClub, $match->awayClub, $match)['input']['price_cents']);
        $this->patch(route('careers.tickets.default', $this->career), ['price' => '45.50'])->assertRedirect();
        $this->assertSame(4550, $this->career->controlledClub->club->fresh()->default_ticket_price_cents);
        $this->assertSame(4550, app(MatchAttendanceService::class)->forecast($this->career, $match->homeClub->fresh(), $match->awayClub, $match)['input']['price_cents']);
        $revision = $this->career->fresh()->progression_revision;
        $this->patch(route('careers.tickets.default', $this->career), ['price' => '45.50'])->assertRedirect();
        $this->assertSame($revision, $this->career->fresh()->progression_revision);
        foreach (['4.99', '500.01', '45.999', ['45.00'], 'oops'] as $price) {
            $this->patch(route('careers.tickets.default', $this->career), ['price' => $price])->assertSessionHasErrors('price');
        }
        $this->assertSame(4550, $this->career->controlledClub->club->fresh()->default_ticket_price_cents);
        $this->get(route('careers.tickets.index', $this->career))->assertOk()->assertSee('R$ 45,50');
        $laterHome = $this->career->matches()->where('home_club_id', $match->home_club_id)->whereKeyNot($match->id)->firstOrFail();
        $this->get(route('careers.tickets.index', ['career' => $this->career, 'match_id' => $laterHome->id]))
            ->assertOk()->assertSee('Partida selecionada:')->assertSee($laterHome->awayClub->name);
        $this->put(route('careers.matches.tickets.update', [$this->career, $laterHome]), ['price' => '70.00'])->assertRedirect();
        $this->assertSame(7000, app(MatchAttendanceService::class)->forecast($this->career, $laterHome->homeClub, $laterHome->awayClub, $laterHome)['input']['price_cents']);
        $this->patch(route('careers.tickets.default', $this->career), ['price' => '50.00'])->assertRedirect();
        $this->assertSame(7000, app(MatchAttendanceService::class)->forecast($this->career, $laterHome->homeClub, $laterHome->awayClub, $laterHome)['input']['price_cents']);
        $this->delete(route('careers.matches.tickets.clear', [$this->career, $laterHome]))->assertRedirect();
        $this->assertSame(5000, app(MatchAttendanceService::class)->forecast($this->career, $laterHome->homeClub->fresh(), $laterHome->awayClub, $laterHome)['input']['price_cents']);
        $this->assertDatabaseCount('match_ticket_policies', 0);
        $this->get(route('careers.tickets.index', ['career' => $this->career, 'match_id' => 999999]))->assertNotFound();
        $this->get(route('careers.finances.show', $this->career))->assertOk()->assertSee('Nenhum lançamento financeiro');
        $this->assertDatabaseCount('career_financial_transactions', 0);
    }

    public function test_per_match_price_uses_domain_preview_and_freezes_on_confirmation(): void
    {
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        $low = $this->getJson(route('careers.matches.tickets.preview', [$this->career, $match]).'?price=10.00')->assertOk()->json();
        $high = $this->getJson(route('careers.matches.tickets.preview', [$this->career, $match]).'?price=120.00')->assertOk()->json();
        $this->assertGreaterThan($high['forecast'], $low['forecast']);
        $this->assertSame($high['forecast'] * 12000, $high['gross_revenue_cents']);
        $this->assertSame($high['gross_revenue_cents'] - $high['operating_cost_cents'], $high['estimated_net_cents']);
        $this->assertSame($match->homeClub->balance_cents + $high['estimated_net_cents'], $high['projected_balance_cents']);
        $this->put(route('careers.matches.tickets.update', [$this->career, $match]), ['price' => '60.00'])
            ->assertRedirect(route('careers.matches.pre-match', [$this->career, $match]));
        $this->assertSame(6000, MatchTicketPolicy::where('match_id', $match->id)->sole()->price_cents);
        $this->get(route('careers.matches.pre-match', [$this->career, $match]))->assertOk()->assertSee('R$ 60,00');
        $plan = app(MatchAttendanceService::class)->forecast($this->career, $match->homeClub, $match->awayClub, $match);
        $this->assertSame(6000, $plan['input']['price_cents']);
        $operation = app(CareerProgressionService::class)->authorizeOperation($this->owner, $this->career, $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $this->assertSame($plan['forecast'], $operation->input['human_attendance_plan']['forecast']);
        $frozenCost = $plan['input']['fixed_cost_cents'];
        $match->homeClub->stadium->update(['fixed_operating_cost_cents' => $frozenCost + 100000]);
        $this->post(route('careers.tickets.default', $this->career), ['price' => '90.00'])->assertMethodNotAllowed();
        $this->patch(route('careers.tickets.default', $this->career), ['price' => '90.00'])->assertSessionHasErrors('price');
        app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        $crowd = $match->fresh()->attendance;
        $this->assertSame(6000, $crowd->ticket_price_cents);
        $this->assertSame($plan['forecast'], $crowd->forecast);
        $this->assertSame($crowd->realized * 6000, CareerFinancialTransaction::where('match_id', $match->id)->where('kind', 'ticket_revenue')->sole()->amount_cents);
        $this->assertSame(-($frozenCost + $crowd->realized * $plan['input']['variable_cost_cents']), CareerFinancialTransaction::where('match_id', $match->id)->where('kind', 'match_operating_cost')->sole()->amount_cents);
        $this->put(route('careers.matches.tickets.update', [$this->career, $match]), ['price' => '40.00'])->assertSessionHasErrors('match');
        $this->delete(route('careers.matches.tickets.clear', [$this->career, $match]))->assertSessionHasErrors('match');
        $this->assertSame(6000, MatchTicketPolicy::where('match_id', $match->id)->sole()->price_cents);
        try {
            MatchTicketPolicy::where('match_id', $match->id)->sole()->update(['price_cents' => 4000]);
            $this->fail('O preço de uma partida concluída foi alterado.');
        } catch (LogicException $exception) {
            $this->assertSame('Preço de partida concluída é imutável.', $exception->getMessage());
        }
    }

    public function test_full_round_posts_reconciled_home_finances_once_including_ai_prices(): void
    {
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        $balances = $this->career->clubs()->pluck('balance_cents', 'id');
        $operation = app(CareerProgressionService::class)->execute($this->owner, $this->career, $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $this->assertDatabaseCount('career_financial_transactions', 10);
        foreach ($this->career->matches()->where('round', 1)->with(['attendance', 'result'])->get() as $game) {
            $crowd = $game->attendance;
            $items = CareerFinancialTransaction::where('match_id', $game->id)->get()->keyBy('kind');
            $this->assertCount(2, $items);
            $this->assertSame($crowd->realized * $crowd->ticket_price_cents, $items['ticket_revenue']->amount_cents);
            $this->assertSame(-($crowd->snapshot['input']['fixed_cost_cents'] + $crowd->realized * $crowd->snapshot['input']['variable_cost_cents']), $items['match_operating_cost']->amount_cents);
            $this->assertSame($game->scheduled_on->toDateString(), $items['ticket_revenue']->occurred_on->toDateString());
            $this->assertSame($items['ticket_revenue']->balance_after_cents + $items['match_operating_cost']->amount_cents, $items['match_operating_cost']->balance_after_cents);
            $this->assertSame($game->result->result['attendance']['ticket_revenue_cents'], $items['ticket_revenue']->amount_cents);
            if ($game->home_club_id !== $this->career->controlledClub->career_club_id) {
                $this->assertSame('ai', $crowd->snapshot['pricing']['actor']);
                $this->assertContains($crowd->ticket_price_cents, array_map(fn ($percent) => max(config('tickets.min_price_cents'), min(config('tickets.max_price_cents'), (int) round(3000 * $percent / 100))), config('tickets.ai_price_percentages')));
            }
        }
        foreach ($this->career->clubs as $club) {
            $this->assertSame($balances[$club->id] + CareerFinancialTransaction::where('career_club_id', $club->id)->sum('amount_cents'), $club->fresh()->balance_cents);
        }
        app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        $this->assertDatabaseCount('career_financial_transactions', 10);
        $this->get(route('careers.finances.show', $this->career))->assertOk()->assertSee('Bilheteria acumulada')->assertSee('Jogo em casa contra');
        $this->get(route('careers.results.show', [$this->career, $match->result]))->assertOk()->assertSee('Despesa operacional');
        $this->get(route('careers.stadium.show', $this->career))->assertOk()->assertSee('Receita acumulada de bilheteria');
    }

    public function test_prices_and_financial_data_are_career_scoped_and_home_only(): void
    {
        $other = User::factory()->create();
        $match = app(CareerCalendar::class)->nextMatch($this->career);
        $away = $this->career->matches()->where('away_club_id', $this->career->controlledClub->career_club_id)->firstOrFail();
        $this->put(route('careers.matches.tickets.update', [$this->career, $away]), ['price' => '20.00'])->assertSessionHasErrors('match');
        $this->actingAs($other);
        $this->get(route('careers.tickets.index', $this->career))->assertForbidden();
        $this->get(route('careers.finances.show', $this->career))->assertForbidden();
        $this->getJson(route('careers.matches.tickets.preview', [$this->career, $match]).'?price=20.00')->assertForbidden();
        $this->patch(route('careers.tickets.default', $this->career), ['price' => '20.00'])->assertForbidden();
        $this->put(route('careers.matches.tickets.update', [$this->career, $match]), ['price' => '20.00'])->assertForbidden();
        $this->assertDatabaseCount('match_ticket_policies', 0);
    }

    public function test_a_financial_failure_rolls_back_results_and_remains_resumable(): void
    {
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        $home = $match->homeClub;
        $balance = $home->balance_cents;
        $operation = app(CareerProgressionService::class)->authorizeOperation($this->owner, $this->career, $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $event = 'eloquent.creating: '.CareerFinancialTransaction::class;
        Event::listen($event, fn () => throw new RuntimeException('Ledger interrupted'));
        try {
            app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
            $this->fail('A falha financeira não interrompeu o processamento.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger interrupted', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('official_match_results', 0);
        $this->assertDatabaseCount('match_attendances', 0);
        $this->assertSame('scheduled', $match->fresh()->status);
        $this->assertSame($balance, $home->fresh()->balance_cents);
        $this->assertSame('pending', $operation->fresh()->status);
        $this->assertDatabaseCount('career_financial_transactions', 0);
        app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        $this->assertDatabaseCount('career_financial_transactions', 10);
    }

    public function test_mandatory_operating_cost_can_make_the_balance_negative(): void
    {
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        $home = $match->homeClub;
        $home->update(['balance_cents' => 0]);
        $home->stadium->update(['available_capacity' => 1]);
        app(CareerProgressionService::class)->execute($this->owner, $this->career, $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $this->assertLessThan(0, $home->fresh()->balance_cents);
        $this->assertSame(CareerFinancialTransaction::where('career_club_id', $home->id)->sum('amount_cents'), $home->fresh()->balance_cents);
        $this->assertDatabaseCount('official_match_results', 5);
    }

    public function test_database_rejects_duplicate_or_cross_career_match_finances(): void
    {
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        app(CareerProgressionService::class)->execute($this->owner, $this->career, $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $entry = CareerFinancialTransaction::where('match_id', $match->id)->where('kind', 'ticket_revenue')->sole();
        $base = $entry->only(['career_id', 'career_club_id', 'career_season_id', 'match_id', 'kind', 'occurred_on', 'amount_cents', 'balance_after_cents']);
        try {
            DB::table('career_financial_transactions')->insert($base + ['source_key' => 'forged-duplicate']);
            $this->fail('O banco aceitou bilheteria duplicada.');
        } catch (QueryException) {
            $this->assertDatabaseCount('career_financial_transactions', 10);
        }

        $other = app(CreateCareer::class)->execute($this->owner, ['name' => 'Outro save', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
        try {
            DB::table('career_financial_transactions')->insert(array_replace($base, [
                'career_id' => $other->id, 'career_club_id' => $other->controlledClub->career_club_id,
                'career_season_id' => $other->seasons()->sole()->id,
            ]) + ['source_key' => 'forged-cross-career']);
            $this->fail('O banco aceitou o vínculo financeiro entre carreiras.');
        } catch (QueryException) {
            $this->assertDatabaseCount('career_financial_transactions', 10);
        }
    }
}
