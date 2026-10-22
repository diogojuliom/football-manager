<?php

namespace Tests\Feature\Attendance;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Attendance\Models\MatchAttendance;
use App\Domain\Career\Models\Career;
use App\Domain\Football\Engine\FootballMatchEngine;
use App\Domain\Football\Engine\ReplayHash;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->career = $this->newCareer();
        $this->actingAs($this->owner);
    }

    private function newCareer(): Career
    {
        return app(CreateCareer::class)->execute($this->owner, ['name' => 'Estádios', 'coach_name' => 'Ana',
            'club_template_id' => ClubTemplate::where('code', 'atletico-ribeira')->firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
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

    public function test_forecast_is_frozen_on_confirmation_and_all_home_crowds_are_archived_once(): void
    {
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        $this->assertSame($this->career->controlledClub->career_club_id, $match->home_club_id);
        $home = $match->homeClub;
        $engagementBefore = $home->support->engagement;
        $loyaltyBefore = $home->support->loyalty;
        $home->stadium->update(['available_capacity' => 2500]);
        $forecast = app(MatchAttendanceService::class)->forecast($this->career, $home->fresh(), $match->awayClub, $match);
        $simultaneous = $this->career->matches()->where('round', 1)->whereKeyNot($match->id)->firstOrFail();
        $otherForecast = app(MatchAttendanceService::class)->forecast($this->career, $simultaneous->homeClub, $simultaneous->awayClub, $simultaneous);
        $this->get(route('careers.matches.pre-match', [$this->career, $match]))->assertOk()
            ->assertSee('2.500')->assertSee('Previsão de público');
        $payload = $this->payload('PLAY_MATCH', ['match_id' => $match->id]);
        $operation = app(CareerProgressionService::class)->authorizeOperation($this->owner, $this->career, $payload);
        $this->assertSame($forecast['forecast'], $operation->input['human_attendance_plan']['forecast']);
        $home->stadium->update(['available_capacity' => 5000]);
        app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        $attendance = $match->fresh()->attendance;
        $this->assertSame(2500, $attendance->capacity);
        $this->assertSame(2500, $attendance->forecast);
        $this->assertSame($attendance->realized, $match->fresh()->result->result['attendance']['present']);
        $afterRound = app(MatchAttendanceService::class)->forecast($this->career, $simultaneous->homeClub->fresh(), $simultaneous->awayClub->fresh(), $simultaneous);
        foreach (['home_points', 'away_points', 'leader_points', 'recent_form', 'away_recent_form'] as $field) {
            $this->assertSame($otherForecast['input'][$field], $afterRound['input'][$field]);
        }
        $score = $match->fresh()->result;
        $expectedChange = ($score->home_goals <=> $score->away_goals) * 2;
        $this->assertSame($engagementBefore + $expectedChange, $home->support->fresh()->engagement);
        $this->assertSame($loyaltyBefore, $home->support->fresh()->loyalty);
        $this->assertSame($match->fresh()->result->result_checksum,
            ReplayHash::of((new FootballMatchEngine)->simulate($match->fresh()->result->input)));
        $this->assertDatabaseCount('match_attendances', 5);
        app(CareerProgressionService::class)->resume($this->owner, $this->career, $operation);
        $this->assertDatabaseCount('match_attendances', 5);
        $this->get(route('careers.stadium.show', $this->career))->assertOk()->assertSee('Histórico de jogos oficiais em casa')
            ->assertSee($attendance->stadium_name)->assertSee('2.500');
        $this->get(route('careers.results.show', [$this->career, $match->result]))->assertOk()->assertSee('Público:');
    }

    public function test_history_and_forecast_are_career_scoped_and_database_rejects_cross_career_link(): void
    {
        $other = $this->newCareer();
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        app(CareerProgressionService::class)->execute($this->owner, $this->career,
            $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $attendance = $match->fresh()->attendance;
        $this->get(route('careers.stadium.show', $other))->assertOk()->assertSee('Nenhum público oficial registrado');
        $this->expectException(LogicException::class);
        app(MatchAttendanceService::class)->forecast($other, $match->homeClub, $match->awayClub, $match);
    }

    public function test_foreign_user_cannot_view_history_or_result_and_cross_career_attendance_fails_fk(): void
    {
        $other = $this->newCareer();
        $this->prepareGame();
        $match = app(CareerCalendar::class)->nextMatch($this->career->fresh());
        app(CareerProgressionService::class)->execute($this->owner, $this->career,
            $this->payload('PLAY_MATCH', ['match_id' => $match->id]));
        $attendance = MatchAttendance::where('match_id', $match->id)->sole();
        $wrongHome = $this->career->clubs()->whereKeyNot($match->home_club_id)->firstOrFail();
        try {
            DB::table('match_attendances')->where('id', $attendance->id)->update(['home_club_id' => $wrongHome->id]);
            $this->fail('A FK deve rejeitar um mandante diferente da partida.');
        } catch (\Illuminate\Database\QueryException) {
            // The archived home club must match the fixture, even on direct SQL writes.
        }
        $values = $attendance->getAttributes();
        unset($values['id']);
        $values['career_id'] = $other->id;
        try {
            DB::table('match_attendances')->insert($values);
            $this->fail('A FK deve rejeitar mandante de outra carreira.');
        } catch (\Illuminate\Database\QueryException) {
            // Composite foreign keys guard even writes that bypass Eloquent.
        }
        $this->actingAs(User::factory()->create());
        $this->get(route('careers.stadium.show', $this->career))->assertForbidden();
        $this->get(route('careers.results.show', [$this->career, $match->result]))->assertForbidden();
    }
}
