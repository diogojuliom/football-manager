<?php

// Standalone integration test: deliberately excluded from PHPUnit's transactional fixtures.
// Requires APP_ENV=testing and a disposable database named football_manager_audit_* (MySQL)
// or an absolute SQLite filename ending in _audit.sqlite. It migrates only that database.
use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Application\Careers\CreateCareer;
use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use App\Domain\Football\Models\OfficialMatchResult;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = DB::connection()->getDriverName();
$database = DB::connection()->getDatabaseName();
if (! $app->environment('testing') || ! (
    ($driver === 'mysql' && str_starts_with($database, 'football_manager_audit_'))
    || ($driver === 'sqlite' && str_starts_with($database, '/') && str_ends_with($database, '_audit.sqlite'))
)) {
    throw new RuntimeException('This test requires an explicitly named disposable audit database and APP_ENV=testing.');
}

if (($argv[1] ?? '') === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $career = Career::findOrFail($job['career_id']);
    touch($argv[2].'.ready');
    $deadline = microtime(true) + 10;
    while (! is_file($job['barrier'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
    }
    try {
        $operation = app(CareerProgressionService::class)->execute($career->owner, $career, $job['input']);
        echo json_encode(['status' => 'completed', 'operation' => $operation->id]);
    } catch (ValidationException $exception) {
        echo json_encode(['status' => 'rejected', 'fields' => array_keys($exception->errors())]);
    }
    exit;
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);
$user = User::factory()->create();
$directory = sys_get_temp_dir().'/football-concurrency-'.Str::uuid();
mkdir($directory, 0700);
try {
    foreach (['ADVANCE_ONE_DAY', 'PLAY_MATCH'] as $action) {
        foreach ([true, false] as $sameKey) {
            $career = app(CreateCareer::class)->execute($user, ['name' => 'Concurrency', 'coach_name' => 'Ana',
                'club_template_id' => ClubTemplate::firstOrFail()->id, 'request_id' => (string) Str::uuid()]);
            $input = ['action' => $action, 'request_id' => (string) Str::uuid(), 'revision' => 0];
            if ($action === 'PLAY_MATCH') {
                app(CareerProgressionService::class)->execute($user, $career, array_replace($input, ['action' => 'ADVANCE_TO_NEXT_MATCH']));
                $career->refresh();
                app(SaveSquad::class)->execute($user, $career, app(SquadSheet::class)->forCareer($career)['settings']);
                $input = ['action' => $action, 'request_id' => (string) Str::uuid(), 'revision' => $career->progression_revision,
                    'match_id' => app(CareerCalendar::class)->nextMatch($career)->id];
            }
            $barrier = $directory.'/barrier-'.$career->id;
            $workers = [];
            for ($i = 0; $i < 2; $i++) {
                $jobPath = $directory.'/job-'.$career->id.'-'.$i;
                $jobInput = $input;
                if (! $sameKey && $i === 1) {
                    $jobInput['request_id'] = (string) Str::uuid();
                }
                file_put_contents($jobPath, json_encode(['career_id' => $career->id, 'barrier' => $barrier, 'input' => $jobInput], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, __FILE__, 'worker', $jobPath], base_path(), null, null, 30);
                $process->start();
                $workers[$jobPath] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/job-'.$career->id.'-*.ready')) !== 2) {
                check(microtime(true) < $deadline, 'Workers did not reach the barrier.');
                usleep(10000);
            }
            touch($barrier);
            $results = [];
            foreach ($workers as $process) {
                $process->wait();
                check($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $completed = array_values(array_filter($results, fn ($r) => $r['status'] === 'completed'));
            check(count($completed) === ($sameKey ? 2 : 1), 'Unexpected acceptance count: '.json_encode($results));
            check(count(array_unique(array_column($completed, 'operation'))) === 1, 'Duplicate operations.');
            check($career->fresh()->progression_revision === $input['revision'] + 1, 'Duplicate revision.');
            check(DB::table('career_operations')->where('career_id', $career->id)->where('status', 'pending')->count() === 0, 'Unfinished operation.');
            check(DB::table('career_processed_days')->where('career_id', $career->id)->count() === ($action === 'PLAY_MATCH' ? 13 : 1), 'Duplicate days.');
            check(OfficialMatchResult::where('career_id', $career->id)->count() === ($action === 'PLAY_MATCH' ? 5 : 0), 'Duplicate matches.');
            echo 'PASS '.$driver.' '.$action.' '.($sameKey ? 'same key' : 'different keys').PHP_EOL;
        }
    }
    $before = $career->players()->orderBy('id')->get()->toArray();
    $resultCount = OfficialMatchResult::count();
    $migration = require database_path('migrations/2026_10_22_000006_index_sports_core_queries.php');
    $migration->down();
    $migration->up();
    check($before === $career->players()->orderBy('id')->get()->toArray(), 'Migration changed players.');
    check($career->matches()->count() === 90 && OfficialMatchResult::count() === $resultCount, 'Migration changed matches.');
    echo 'PASS '.$driver.' index migration rollback and reapplication'.PHP_EOL;
} finally {
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
