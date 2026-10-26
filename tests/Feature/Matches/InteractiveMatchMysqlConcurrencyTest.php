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
use App\Domain\Football\Engine\V5\Simulation;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InteractiveMatchMysqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Este teste exige MySQL para validar bloqueios de linha reais.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Este teste exige pcntl para abrir conexões concorrentes.');
        }
        $this->seed();
    }

    public function test_same_and_competing_advances_are_serialized_without_duplicate_rng_consumption(): void
    {
        [$user, $career, $session] = $this->runningSession('Concurrent tabs');
        $requestId = (string) Str::uuid();
        $sameRequestJobs = [
            $this->advanceJob($user, $career, $session, $requestId, 1),
            $this->advanceJob($user, $career, $session, $requestId, 1),
        ];
        $sameRequestResults = $this->race($sameRequestJobs);

        $this->assertSame(['ok', 'ok'], array_column($sameRequestResults, 'status'));
        $this->assertSame(ReplayHash::of($sameRequestResults[0]['response']), ReplayHash::of($sameRequestResults[1]['response']));
        $afterSameRequest = $session->fresh();
        $this->assertSame(1, $afterSameRequest->minute);
        $this->assertSame(2, $afterSameRequest->revision);
        $this->assertSame(2, $afterSameRequest->requests()->count());

        $expected = (new Simulation($afterSameRequest->input, $afterSameRequest->state))->advance(1);
        $competingJobs = [
            $this->advanceJob($user, $career, $session, (string) Str::uuid(), 2),
            $this->advanceJob($user, $career, $session, (string) Str::uuid(), 2),
        ];
        $competingResults = $this->race($competingJobs);
        $statuses = array_column($competingResults, 'status');
        sort($statuses);

        $this->assertSame(['ok', 'validation'], $statuses);
        $final = $session->fresh();
        $this->assertSame(2, $final->minute);
        $this->assertSame(3, $final->revision);
        $this->assertSame(3, $final->requests()->count());
        $this->assertSame(ReplayHash::of($expected), ReplayHash::of($final->state));

        $at29 = app(InteractiveMatchService::class)->advance($user, $career, $session, [
            'request_id' => (string) Str::uuid(), 'revision' => $final->revision, 'minutes' => 27,
        ]);
        $hydrationResults = $this->race([
            $this->advanceJob($user, $career, $session, (string) Str::uuid(), $at29['revision']),
            $this->advanceJob($user, $career, $session, (string) Str::uuid(), $at29['revision']),
        ]);
        $hydrationStatuses = array_column($hydrationResults, 'status');
        sort($hydrationStatuses);
        $this->assertSame(['ok', 'validation'], $hydrationStatuses);
        $atBreak = $session->fresh();
        $this->assertSame(30, $atBreak->minute);
        $this->assertTrue($atBreak->paused);
        $this->assertSame('hydration_break', $atBreak->state['status']);
        $this->assertCount(1, array_filter($atBreak->state['events'], fn (array $event): bool => $event['type'] === 'hydration_break_start'));
    }

    public function test_independent_careers_advance_concurrently_without_cross_contamination(): void
    {
        [$firstUser, $firstCareer, $firstSession] = $this->runningSession('Independent A');
        [$secondUser, $secondCareer, $secondSession] = $this->runningSession('Independent B');
        $firstBefore = $firstSession->fresh();
        $secondBefore = $secondSession->fresh();
        $firstExpected = (new Simulation($firstBefore->input, $firstBefore->state))->advance(1);
        $secondExpected = (new Simulation($secondBefore->input, $secondBefore->state))->advance(1);

        $results = $this->race([
            $this->advanceJob($firstUser, $firstCareer, $firstSession, (string) Str::uuid(), 1),
            $this->advanceJob($secondUser, $secondCareer, $secondSession, (string) Str::uuid(), 1),
        ]);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $firstAfter = $firstSession->fresh();
        $secondAfter = $secondSession->fresh();
        $this->assertSame(ReplayHash::of($firstExpected), ReplayHash::of($firstAfter->state));
        $this->assertSame(ReplayHash::of($secondExpected), ReplayHash::of($secondAfter->state));
        $this->assertSame(1, $firstAfter->minute);
        $this->assertSame(1, $secondAfter->minute);
        $this->assertSame($firstCareer->id, $firstAfter->career_id);
        $this->assertSame($secondCareer->id, $secondAfter->career_id);
        $this->assertNotSame($firstAfter->input['seed'], $secondAfter->input['seed']);
        $this->assertNotSame(ReplayHash::of($firstAfter->state), ReplayHash::of($secondAfter->state));
    }

    private function runningSession(string $name): array
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
        $career->refresh();
        $session = app(InteractiveMatchService::class)->start($user, $career, app(CareerCalendar::class)->nextMatch($career));
        app(InteractiveMatchService::class)->control($user, $career, $session, [
            'request_id' => (string) Str::uuid(), 'revision' => 0, 'action' => 'resume',
        ]);

        return [$user, $career, $session->fresh()];
    }

    private function advanceJob(User $user, Career $career, InteractiveMatchSession $session, string $requestId, int $revision): array
    {
        return [
            'user_id' => $user->id, 'career_id' => $career->id, 'session_id' => $session->id,
            'request_id' => $requestId, 'revision' => $revision,
        ];
    }

    private function race(array $jobs): array
    {
        $directory = sys_get_temp_dir().'/fm-concurrency-'.Str::uuid();
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the concurrency barrier.');
        }
        $barrier = $directory.'/start';
        $children = [];
        DB::disconnect();

        foreach ($jobs as $index => $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork the concurrency worker.');
            }
            if ($pid === 0) {
                DB::purge();
                while (! file_exists($barrier)) {
                    usleep(1000);
                }
                try {
                    $response = app(InteractiveMatchService::class)->advance(
                        User::findOrFail($job['user_id']),
                        Career::findOrFail($job['career_id']),
                        InteractiveMatchSession::findOrFail($job['session_id']),
                        ['request_id' => $job['request_id'], 'revision' => $job['revision'], 'minutes' => 1],
                    );
                    $result = ['status' => 'ok', 'response' => $response];
                } catch (ValidationException $exception) {
                    $result = ['status' => 'validation', 'errors' => $exception->errors()];
                } catch (\Throwable $exception) {
                    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
                }
                file_put_contents($directory.'/result-'.$index.'.json', json_encode($result, JSON_THROW_ON_ERROR));
                exit($result['status'] === 'error' ? 1 : 0);
            }
            $children[] = $pid;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'A concurrency worker failed.');
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($jobs) as $index) {
            $results[] = json_decode(file_get_contents($directory.'/result-'.$index.'.json'), true, 512, JSON_THROW_ON_ERROR);
            unlink($directory.'/result-'.$index.'.json');
        }
        unlink($barrier);
        rmdir($directory);

        return $results;
    }
}
