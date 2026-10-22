<?php

namespace Tests\Feature\Careers;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Competitions\StandingsService;
use App\Application\Matches\PlayOfficialMatch;
use App\Application\Matches\SimulateFriendly;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerEvent;
use App\Domain\Career\Models\CareerOperation;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ProgressionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create(['password' => 'secret-password']);
        $this->career = $this->newCareer();
        $this->actingAs($this->owner);
    }

    private function newCareer(): Career
    {
        return app(CreateCareer::class)->execute($this->owner, ['name' => 'Calendário', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
    }

    private function payload(string $action = 'ADVANCE_TO_NEXT_MATCH', array $extra = []): array
    {
        return $extra + ['action' => $action, 'request_id' => (string) Str::uuid(), 'revision' => $this->career->fresh()->progression_revision];
    }

    private function advance(string $action = 'ADVANCE_TO_NEXT_MATCH', array $extra = []): CareerOperation
    {
        return app(CareerProgressionService::class)->execute($this->owner, $this->career, $this->payload($action, $extra));
    }

    private function saveLineup(): void
    {
        $this->career->refresh()->unsetRelation('controlledClub');
        $current = $this->career->controlledClub->club->lineup;
        // Request a fresh available suggestion, including replacements for suspended players.
        $formation = $current?->formation === '4-4-2' ? '4-3-3' : '4-4-2';
        $settings = app(SquadSheet::class)->forCareer($this->career, $formation)['settings'];
        app(SaveSquad::class)->execute($this->owner, $this->career, $settings);
    }

    private function nextMatch()
    {
        return app(CareerCalendar::class)->nextMatch($this->career->fresh());
    }

    private function event(string $date, string $kind = 'administrative_decision'): CareerEvent
    {
        return CareerEvent::create(['career_id' => $this->career->id, 'career_season_id' => $this->career->seasons()->sole()->id,
            'event_key' => (string) Str::uuid(), 'kind' => $kind, 'title' => 'Aviso da diretoria',
            'scheduled_on' => $date, 'requires_decision' => $kind === 'administrative_decision', 'status' => 'pending']);
    }

    private function aiMatches()
    {
        $id = $this->career->controlledClub->career_club_id;

        return $this->career->matches()->where('round', 1)->where('home_club_id', '!=', $id)->where('away_club_id', '!=', $id)->orderBy('id')->get();
    }

    public function test_one_day_recovers_once_without_inventing_financial_or_suspension_effects(): void
    {
        $players = $this->career->players()->take(3)->get();
        $players[0]->update(['fitness' => 50, 'suspended_matches' => 1]);
        $players[1]->update(['fitness' => 99]);
        $players[2]->update(['fitness' => 40, 'injured_until' => '2026-01-03']);
        $balances = $this->career->clubs()->pluck('balance_cents')->all();
        $payload = $this->payload('ADVANCE_ONE_DAY');
        $first = app(CareerProgressionService::class)->execute($this->owner, $this->career, $payload);
        $again = app(CareerProgressionService::class)->execute($this->owner, $this->career, $payload);
        $this->assertTrue($first->is($again));
        $this->assertSame('2026-01-02', $this->career->fresh()->current_date->toDateString());
        $this->assertSame(58, $players[0]->fresh()->fitness);
        $this->assertSame(1, $players[0]->fresh()->suspended_matches);
        $this->assertSame(100, $players[1]->fresh()->fitness);
        $this->assertSame(40, $players[2]->fresh()->fitness);
        $this->assertDatabaseCount('career_processed_days', 1);
        $this->assertDatabaseCount('official_match_results', 0);
        $this->assertSame($balances, $this->career->clubs()->pluck('balance_cents')->all());
    }

    public function test_next_match_processes_intermediate_days_and_ai_fixtures_then_stops_before_user_game(): void
    {
        $early = $this->aiMatches()->first();
        $early->update(['scheduled_on' => '2026-01-05']);
        $notice = $this->event('2026-01-04', 'notice');
        $op = $this->advance();
        $this->assertSame('2026-01-14', $op->result['current_date']);
        $this->assertSame(CareerState::PreMatch, $this->career->fresh()->state);
        $this->assertDatabaseCount('career_processed_days', 13);
        $this->assertSame(range(2, 14), DB::table('career_processed_days')->orderBy('id')->pluck('processed_on')->map(fn ($d) => (int) substr($d, 8))->all());
        $this->assertSame('2026-01-04', $notice->fresh()->processed_on->toDateString());
        $this->assertSame('2026-01-05', OfficialMatchResult::sole()->played_on->toDateString());
        $this->assertSame('scheduled', $this->nextMatch()->status);
        $this->assertSame([$early->id], $op->result['played_match_ids']);
        $this->get(route('careers.show', $this->career))->assertOk()->assertSee('Pré-jogo')->assertSee('Jogar partida');
        $this->assertDatabaseCount('official_match_results', 1);
    }

    public function test_next_event_ignores_informational_recovery_and_stops_at_a_required_decision(): void
    {
        $notice = $this->event('2026-01-02', 'notice');
        $decision = $this->event('2026-01-04');
        $this->advance('ADVANCE_TO_NEXT_EVENT');
        $this->assertSame('2026-01-04', $this->career->fresh()->current_date->toDateString());
        $this->assertSame('processed', $notice->fresh()->status);
        $this->assertSame('pending', $decision->fresh()->status);
        $this->post(route('careers.progression.store', $this->career), $this->payload())->assertSessionHasErrors('progression');
        $this->advance('ACKNOWLEDGE_EVENT', ['event_id' => $decision->id]);
        $this->assertSame('processed', $decision->fresh()->status);
        $this->assertSame('2026-01-04', $decision->fresh()->processed_on->toDateString());
        $this->advance('ADVANCE_TO_NEXT_EVENT');
        $this->assertSame('2026-01-14', $this->career->fresh()->current_date->toDateString());
        $this->assertSame(CareerState::PreMatch, $this->career->fresh()->state);
    }

    public function test_next_match_cannot_skip_a_mandatory_stop_or_leave_ai_games_behind_after_acknowledgment(): void
    {
        $decision = $this->event('2026-01-05');
        $this->aiMatches()->first()->update(['scheduled_on' => '2026-01-05']);
        $this->advance();
        $this->assertSame('2026-01-05', $this->career->fresh()->current_date->toDateString());
        $this->assertDatabaseCount('official_match_results', 0);
        $this->advance('ACKNOWLEDGE_EVENT', ['event_id' => $decision->id]);
        $this->advance();
        $this->assertDatabaseCount('official_match_results', 1);
        $this->assertSame('2026-01-05', OfficialMatchResult::sole()->played_on->toDateString());
        $this->assertSame('2026-01-14', $this->career->fresh()->current_date->toDateString());
    }

    public function test_one_day_arrives_at_pre_match_and_further_advances_require_confirmation(): void
    {
        $this->career->update(['current_date' => '2026-01-13']);
        $this->advance('ADVANCE_ONE_DAY');
        $this->assertSame(CareerState::PreMatch, $this->career->fresh()->state);
        foreach (['ADVANCE_ONE_DAY', 'ADVANCE_TO_NEXT_MATCH', 'ADVANCE_TO_NEXT_EVENT'] as $action) {
            $this->post(route('careers.progression.store', $this->career), $this->payload($action))->assertSessionHasErrors('progression');
        }
        $this->get(route('careers.matches.pre-match', [$this->career, $this->nextMatch()]))->assertOk()->assertSee('Confirmar e jogar partida');
        $this->assertDatabaseCount('official_match_results', 0);
        $this->post(route('careers.progression.store', $this->career), $this->payload('PLAY_MATCH', ['match_id' => $this->nextMatch()->id]))->assertSessionHasErrors('lineup');
        $this->assertSame(CareerState::PreMatch, $this->career->fresh()->state);
        $this->assertDatabaseCount('career_operations', 1);
    }

    public function test_confirmed_match_persists_round_applies_physical_effects_and_replays_after_json_roundtrip(): void
    {
        $this->advance();
        $this->saveLineup();
        $match = $this->nextMatch();
        $sheet = app(SquadSheet::class)->forCareer($this->career);
        $unused = $this->career->controlledClub->club->players()->whereNotIn('id', [...$sheet['settings']['starters'], ...$sheet['settings']['substitutes']])->firstOrFail();
        $unused->update(['suspended_matches' => 2]);
        $payload = $this->payload('PLAY_MATCH', ['match_id' => $match->id]);
        $this->post(route('careers.progression.store', $this->career), $payload)->assertRedirect();
        $this->post(route('careers.progression.store', $this->career), $payload)->assertRedirect();
        $this->assertDatabaseCount('official_match_results', 5);
        $this->assertDatabaseCount('match_attendances', 5);
        $this->assertSame('2026-01-14', $this->career->fresh()->current_date->toDateString());
        $this->assertSame(CareerState::AwaitingDecision, $this->career->fresh()->state);
        $this->assertSame(1, $unused->fresh()->suspended_matches);
        foreach (OfficialMatchResult::all() as $record) {
            $this->assertSame($record->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($record->input)));
            $this->assertSame($record->attendance->realized, $record->result['attendance']['present']);
            $this->assertSame($record->attendance->capacity, $record->result['attendance']['capacity']);
            $this->assertLessThanOrEqual($record->attendance->capacity, $record->attendance->realized);
            foreach (['home', 'away'] as $side) {
                foreach ($record->result[$side]['players'] as $stats) {
                    $player = $this->career->players()->findOrFail($stats['player_id']);
                    $this->assertSame($stats['final_fitness'], $player->fitness);
                    if ($stats['red_cards'] > 0) {
                        $this->assertSame(1, $player->suspended_matches);
                    }
                }
            }
        }
        $record = $match->fresh()->result;
        $this->assertSame($sheet['settings']['formation'], $record->input[$match->home_club_id === $this->career->controlledClub->career_club_id ? 'home' : 'away']['tactics']['formation']);
        $this->get(route('careers.results.show', [$this->career, $record]))->assertOk()->assertSee('Partida oficial')->assertSee('Condição final aplicada');
        $op = CareerOperation::where('kind', 'PLAY_MATCH')->sole();
        $this->assertSame($this->career->matches()->where('round', 1)->orderBy('id')->pluck('id')->all(), $op->result['played_match_ids']);
        $this->post(route('careers.progression.store', $this->career), $this->payload('PLAY_MATCH', ['match_id' => $match->id]))->assertSessionHasErrors('match_id');
    }

    public function test_future_foreign_and_invalid_matches_cannot_be_confirmed(): void
    {
        $other = $this->newCareer();
        foreach ([$this->nextMatch()->id, $other->matches()->first()->id, 999999] as $id) {
            $this->post(route('careers.progression.store', $this->career), $this->payload('PLAY_MATCH', ['match_id' => $id]))->assertSessionHasErrors('match_id');
        }
        $this->get(route('careers.matches.pre-match', [$this->career, $this->nextMatch()]))->assertStatus(409);
        $this->get(route('careers.matches.pre-match', [$this->career, $other->matches()->first()]))->assertNotFound();
        $this->assertDatabaseCount('career_operations', 0);
    }

    public function test_failure_rolls_back_days_results_and_fitness_but_keeps_recoverable_authorization(): void
    {
        foreach ($this->aiMatches()->take(2) as $match) {
            $match->update(['scheduled_on' => '2026-01-05']);
        }
        $player = $this->career->players()->first();
        $player->update(['fitness' => 40]);
        $real = app(PlayOfficialMatch::class);
        $calls = 0;
        $this->mock(PlayOfficialMatch::class)->shouldReceive('execute')->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('Interrupted operation');
            }

            return $real->execute(...$args);
        });
        try {
            $this->advance();
            $this->fail('The processing failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted operation', $exception->getMessage());
        }
        $operation = CareerOperation::sole();
        $this->assertSame('pending', $operation->status);
        $this->assertSame(CareerState::Processing, $this->career->fresh()->state);
        $this->assertSame('2026-01-01', $this->career->fresh()->current_date->toDateString());
        $this->assertSame(40, $player->fresh()->fitness);
        $this->assertDatabaseCount('career_processed_days', 0);
        $this->assertDatabaseCount('official_match_results', 0);
        $this->assertDatabaseCount('match_attendances', 0);
        $this->post(route('careers.progression.store', $this->career), $this->payload())->assertSessionHasErrors('progression');
        $this->post(route('careers.squad.update', $this->career), [])->assertStatus(405);
        $this->put(route('careers.squad.update', $this->career), [])->assertSessionHasErrors('lineup');
        $this->get(route('careers.show', $this->career))->assertOk()->assertSee('Retomar operação autorizada');
        $this->app->instance(PlayOfficialMatch::class, $real);
        $this->post(route('careers.operations.resume', [$this->career, $operation]))->assertRedirect();
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame('2026-01-14', $this->career->fresh()->current_date->toDateString());
        $this->assertDatabaseCount('career_processed_days', 13);
        $this->assertDatabaseCount('official_match_results', 2);
        $this->assertDatabaseCount('match_attendances', 2);
        $this->post(route('careers.operations.resume', [$this->career, $operation]))->assertRedirect();
        $this->assertDatabaseCount('official_match_results', 2);
    }

    public function test_reserved_operation_survives_disconnect_and_only_explicit_resume_processes_it(): void
    {
        $operation = app(CareerProgressionService::class)->authorizeOperation($this->owner, $this->career, $this->payload());
        $this->post('/logout');
        $this->travel(80)->days();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'secret-password'])->assertRedirect();
        $this->get(route('careers.show', $this->career))->assertOk()->assertSee('Retomar operação autorizada');
        $this->assertSame('2026-01-01', $this->career->fresh()->current_date->toDateString());
        $this->assertDatabaseCount('career_processed_days', 0);
        $this->assertSame('pending', $operation->fresh()->status);
        $this->post(route('careers.operations.resume', [$this->career, $operation]))->assertRedirect();
        $this->assertSame('2026-01-14', $this->career->fresh()->current_date->toDateString());
        $this->travelBack();
    }

    public function test_confirmed_game_failure_rolls_back_all_physical_effects_and_recovers_the_frozen_user_team(): void
    {
        $this->advance();
        $this->saveLineup();
        $match = $this->nextMatch();
        $before = $this->career->players()->orderBy('id')->get(['id', 'fitness', 'suspended_matches'])->toArray();
        $payload = $this->payload('PLAY_MATCH', ['match_id' => $match->id]);
        $real = app(PlayOfficialMatch::class);
        $this->mock(PlayOfficialMatch::class)->shouldReceive('execute')->andReturnUsing(function (...$args) use ($real, $match) {
            $record = $real->execute(...$args);
            if ($record->match_id === $match->id) {
                throw new RuntimeException('Failure after recording the user game');
            }

            return $record;
        });
        try {
            app(CareerProgressionService::class)->execute($this->owner, $this->career, $payload);
            $this->fail('The failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failure after recording the user game', $exception->getMessage());
        }
        $operation = CareerOperation::where('kind', 'PLAY_MATCH')->sole();
        $this->assertSame('pending', $operation->status);
        $this->assertSame($before, $this->career->players()->orderBy('id')->get(['id', 'fitness', 'suspended_matches'])->toArray());
        $this->assertDatabaseCount('official_match_results', 0);
        $this->assertDatabaseCount('match_attendances', 0);
        $this->assertSame(90, $this->career->matches()->where('status', 'scheduled')->count());
        $this->app->instance(PlayOfficialMatch::class, $real);
        app(CareerProgressionService::class)->execute($this->owner, $this->career, $payload);
        $record = $match->fresh()->result;
        $side = $match->home_club_id === $this->career->controlledClub->career_club_id ? 'home' : 'away';
        $this->assertSame(ReplayHash::of($operation->input['human_team']), ReplayHash::of($record->input[$side]));
        $this->assertDatabaseCount('official_match_results', 5);
        $this->assertDatabaseCount('match_attendances', 5);
        $this->assertSame('completed', $operation->fresh()->status);
    }

    public function test_database_rejects_duplicate_and_cross_career_official_results(): void
    {
        $this->advance();
        $this->saveLineup();
        $this->advance('PLAY_MATCH', ['match_id' => $this->nextMatch()->id]);
        $record = OfficialMatchResult::firstOrFail();
        $other = $this->newCareer();
        $values = $record->getAttributes();
        unset($values['id']);
        foreach ([$values, array_replace($values, ['match_id' => $other->matches()->first()->id])] as $invalid) {
            try {
                DB::table('official_match_results')->insert($invalid);
                $this->fail('The database must reject this result.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }
        $this->assertDatabaseCount('official_match_results', 5);
    }

    public function test_stale_tabs_and_conflicting_retries_cannot_duplicate_progression(): void
    {
        $first = $this->payload('ADVANCE_ONE_DAY');
        $staleTab = $this->payload('ADVANCE_ONE_DAY');
        $this->post(route('careers.progression.store', $this->career), $first)->assertRedirect();
        $this->post(route('careers.progression.store', $this->career), $first)->assertRedirect();
        $this->post(route('careers.progression.store', $this->career), $staleTab)->assertSessionHasErrors('revision');
        $this->post(route('careers.progression.store', $this->career), array_replace($first, ['action' => 'ADVANCE_TO_NEXT_MATCH']))->assertSessionHasErrors('request_id');
        $this->assertSame('2026-01-02', $this->career->fresh()->current_date->toDateString());
        $this->assertSame(1, $this->career->fresh()->progression_revision);
        $this->assertDatabaseCount('career_processed_days', 1);
    }

    public function test_get_login_and_wall_clock_never_progress_either_of_two_isolated_careers(): void
    {
        $other = $this->newCareer();
        $otherPlayers = $other->players()->pluck('fitness')->all();
        $this->advance();
        $this->assertSame('2026-01-01', $other->fresh()->current_date->toDateString());
        $this->assertSame($otherPlayers, $other->players()->pluck('fitness')->all());
        $date = $this->career->fresh()->current_date->toDateString();
        $this->post('/logout');
        $this->travel(365)->days();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'secret-password']);
        $this->get(route('careers.show', $this->career))->assertOk();
        $this->get(route('careers.show', $other))->assertOk();
        $this->assertSame($date, $this->career->fresh()->current_date->toDateString());
        $this->assertSame('2026-01-01', $other->fresh()->current_date->toDateString());
        $this->assertSame(CareerState::PreMatch, $this->career->fresh()->state);
        $this->assertDatabaseCount('official_match_results', 0);
        $this->travelBack();
    }

    public function test_guests_other_owners_and_cross_career_resume_are_rejected(): void
    {
        $payload = $this->payload();
        $other = $this->newCareer();
        $operation = app(CareerProgressionService::class)->authorizeOperation($this->owner, $this->career, $payload);
        $this->post(route('careers.operations.resume', [$other, $operation]))->assertNotFound();
        $this->post('/logout');
        $this->actingAs(User::factory()->create());
        $this->post(route('careers.progression.store', $this->career), $payload)->assertForbidden();
        $this->post(route('careers.operations.resume', [$this->career, $operation]))->assertForbidden();
        $this->post('/logout');
        $this->post(route('careers.progression.store', $this->career), $payload)->assertRedirect(route('login'));
        $this->post(route('careers.operations.resume', [$this->career, $operation]))->assertRedirect(route('login'));
        $this->assertDatabaseCount('career_processed_days', 0);
    }

    public function test_standings_and_scorers_use_official_results_and_frozen_configurable_rules(): void
    {
        $this->saveLineup();
        app(SimulateFriendly::class)->execute($this->owner, $this->career, ['away_club_id' => $this->aiMatches()->first()->home_club_id,
            'seed' => 'not-official', 'request_id' => (string) Str::uuid()]);
        $edition = CompetitionEdition::where('career_id', $this->career->id)->sole();
        $this->assertSame(0, array_sum(array_column(app(StandingsService::class)->standings($edition), 'played')));
        $edition->update(['rules' => array_replace($edition->rules, ['points_for_win' => 5, 'points_for_draw' => 2,
            'points_for_loss' => -1, 'tiebreakers' => ['goals_for', 'wins', 'club_code']])]);
        $this->advance();
        $this->advance('PLAY_MATCH', ['match_id' => $this->nextMatch()->id]);
        $table = app(StandingsService::class)->standings($edition);
        $this->assertSame(10, array_sum(array_column($table, 'played')));
        foreach ($table as $row) {
            $this->assertSame($row['wins'] * 5 + $row['draws'] * 2 - $row['losses'], $row['points']);
            $this->assertSame($row['goals_for'] - $row['goals_against'], $row['goal_difference']);
        }
        $expected = $table;
        usort($expected, fn ($a, $b) => $b['points'] <=> $a['points'] ?: $b['goals_for'] <=> $a['goals_for'] ?: $b['wins'] <=> $a['wins'] ?: strcmp($a['club_code'], $b['club_code']));
        $this->assertSame($expected, $table);
        $this->assertSame(array_sum(array_column($table, 'goals_for')), array_sum(array_column(app(StandingsService::class)->scorers($edition), 'goals')));
    }

    public function test_a_complete_season_has_90_unique_results_champion_and_frozen_final_statistics(): void
    {
        for ($round = 1; $round <= 18; $round++) {
            $this->advance();
            $this->assertSame(CareerState::PreMatch, $this->career->fresh()->state);
            $this->saveLineup();
            $match = $this->nextMatch();
            $this->assertSame($round, $match->round);
            $this->advance('PLAY_MATCH', ['match_id' => $match->id]);
            $this->assertSame($round * 5, OfficialMatchResult::count());
            $this->assertSame($match->scheduled_on->toDateString(), $this->career->fresh()->current_date->toDateString());
        }
        $this->assertNull($this->nextMatch());
        $this->assertSame('active', $this->career->seasons()->sole()->status);
        $this->advance('ADVANCE_TO_NEXT_EVENT');
        $season = $this->career->seasons()->sole();
        $edition = CompetitionEdition::where('career_id', $this->career->id)->sole();
        $this->assertSame('completed', $season->status);
        $this->assertSame('completed', $edition->status);
        $this->assertSame(CareerState::AwaitingSeason, $this->career->fresh()->state);
        $this->assertSame(\Carbon\CarbonImmutable::parse($this->career->matches()->max('scheduled_on'))->addDay()->toDateString(), $season->ended_on->toDateString());
        $this->assertDatabaseCount('official_match_results', 90);
        $this->assertDatabaseCount('match_attendances', 90);
        $this->assertDatabaseCount('career_financial_transactions', 180);
        $this->assertSame(90, DB::table('match_attendances')->distinct()->count('match_id'));
        $this->assertSame(0, DB::table('match_attendances')->whereColumn('realized', '>', 'capacity')->count());
        $this->assertSame(90, OfficialMatchResult::distinct()->count('match_id'));
        $this->assertSame(0, $this->career->matches()->where('status', 'scheduled')->count());
        $table = app(StandingsService::class)->standings($edition);
        $scorers = app(StandingsService::class)->scorers($edition);
        $this->assertSame(ReplayHash::of($table), ReplayHash::of($season->summary[0]['standings']));
        $this->assertSame(ReplayHash::of($scorers), ReplayHash::of($season->summary[0]['scorers']));
        $this->assertSame($table[0]['club_id'], $season->summary[0]['champion_club_id']);
        $this->assertSame(180, array_sum(array_column($table, 'played')));
        $this->assertSame(array_sum(array_column($table, 'goals_for')), array_sum(array_column($table, 'goals_against')));
        $this->assertSame(array_sum(array_column($table, 'goals_for')), array_sum(array_column($scorers, 'goals')));
        foreach ($table as $row) {
            $this->assertSame(18, $row['played']);
        }
        foreach (OfficialMatchResult::all() as $result) {
            $this->assertSame($result->result_checksum, ReplayHash::of((new FootballMatchEngine)->simulate($result->input)));
        }
        foreach ($this->career->clubs as $club) {
            $total = CareerFinancialTransaction::where('career_id', $this->career->id)->where('career_club_id', $club->id)->sum('amount_cents');
            $this->assertSame($club->initial_balance_cents + $total, $club->fresh()->balance_cents);
        }
        $this->get(route('careers.show', $this->career))->assertOk()->assertSee('Temporada encerrada')->assertSee($table[0]['name']);
        $this->post(route('careers.progression.store', $this->career), $this->payload('ADVANCE_ONE_DAY'))->assertSessionHasErrors('progression');
        $this->assertSame(1, $this->career->seasons()->count());
    }

    public function test_official_results_are_immutable_and_not_visible_to_other_owners_or_careers(): void
    {
        $this->advance();
        $this->saveLineup();
        $this->advance('PLAY_MATCH', ['match_id' => $this->nextMatch()->id]);
        $result = OfficialMatchResult::firstOrFail();
        $this->get(route('careers.results.show', [$this->newCareer(), $result]))->assertNotFound();
        $this->post('/logout');
        $this->actingAs(User::factory()->create())->get(route('careers.results.show', [$this->career, $result]))->assertForbidden();
        $this->expectException(LogicException::class);
        $result->update(['home_goals' => 999]);
    }

    public function test_invalid_actions_and_forged_time_or_scores_do_not_change_the_world(): void
    {
        foreach ([['action' => 'UNKNOWN'], ['revision' => -1], ['revision' => []], ['request_id' => 'invalid']] as $invalid) {
            $this->post(route('careers.progression.store', $this->career), array_replace($this->payload(), $invalid))->assertSessionHasErrors();
        }
        $this->post(route('careers.progression.store', $this->career), $this->payload('ADVANCE_ONE_DAY', [
            'current_date' => '2099-01-01', 'target_date' => '2099-01-01', 'fitness' => 100, 'home_goals' => 999,
        ]))->assertRedirect();
        $this->assertSame('2026-01-02', $this->career->fresh()->current_date->toDateString());
        $this->assertDatabaseCount('official_match_results', 0);
    }
}
