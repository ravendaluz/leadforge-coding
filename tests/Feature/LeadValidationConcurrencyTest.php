<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadValidationResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LeadValidationConcurrencyTest extends TestCase
{
    private string $tempDirectory;

    private string $databasePath;

    private string $originalDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabasePath = (string) config('database.connections.sqlite.database');
        $this->tempDirectory = sys_get_temp_dir().'/leadforge-concurrency-'.bin2hex(random_bytes(8));

        if (! mkdir($concurrentDirectory = $this->tempDirectory, 0777, true) && ! is_dir($concurrentDirectory)) {
            $this->fail('Unable to create temporary concurrency test directory.');
        }

        $this->databasePath = $this->tempDirectory.'/concurrency.sqlite';

        if (touch($this->databasePath) === false) {
            $this->fail('Unable to create temporary SQLite database for concurrency test.');
        }

        $this->useSqliteDatabase($this->databasePath);

        Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        config([
            'database.connections.sqlite.database' => $this->originalDatabasePath,
            'database.connections.sqlite.busy_timeout' => null,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        if (isset($this->tempDirectory) && is_dir($this->tempDirectory)) {
            $files = scandir($this->tempDirectory);

            if ($files !== false) {
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    @unlink($this->tempDirectory.'/'.$file);
                }
            }

            @rmdir($this->tempDirectory);
        }

        parent::tearDown();
    }

    public function test_two_workers_share_one_provider_result_and_reuse_it_after_restart(): void
    {
        $lead = Lead::create([
            'email' => 'concurrency@leadforge.test',
            'phone' => '+15550000000',
            'country' => 'US',
        ]);

        $workerPlans = [
            'worker-a' => ['expectedVerdict' => 'valid', 'expectedAttempts' => 3],
            'worker-b' => ['expectedVerdict' => 'invalid', 'expectedAttempts' => 2],
        ];

        [$firstRunResults, $firstRunCounts] = $this->runConcurrentWorkers(
            $lead->id,
            $workerPlans,
            $this->tempDirectory.'/first-run-counts.json',
        );

        $winningWorker = $this->assertExactlyOneWorkerReachedTheProvider($workerPlans, $firstRunCounts);
        $losingWorker = $winningWorker === 'worker-a' ? 'worker-b' : 'worker-a';
        $expectedVerdict = $workerPlans[$winningWorker]['expectedVerdict'];
        $expectedAttempts = $workerPlans[$winningWorker]['expectedAttempts'];

        $this->assertSame($expectedVerdict, $firstRunResults['worker-a']['verdict']);
        $this->assertSame($expectedVerdict, $firstRunResults['worker-b']['verdict']);
        $this->assertFalse($firstRunResults[$winningWorker]['reused']);
        $this->assertTrue($firstRunResults[$losingWorker]['reused']);

        $storedResult = LeadValidationResult::query()->sole();
        $this->assertSame($expectedVerdict, $storedResult->verdict);
        $this->assertSame($expectedAttempts, $storedResult->attempts);

        [$secondRunResults, $secondRunCounts] = $this->runConcurrentWorkers(
            $lead->id,
            $workerPlans,
            $this->tempDirectory.'/second-run-counts.json',
        );

        $this->assertSame([], array_filter($secondRunCounts, static fn (int $count): bool => $count > 0));
        $this->assertTrue($secondRunResults['worker-a']['reused']);
        $this->assertTrue($secondRunResults['worker-b']['reused']);
        $this->assertSame($expectedVerdict, $secondRunResults['worker-a']['verdict']);
        $this->assertSame($expectedVerdict, $secondRunResults['worker-b']['verdict']);
    }

    /**
     * @param  array<string, array{expectedVerdict: string, expectedAttempts: int}>  $workerPlans
     * @return array{0: array<string, array{worker: string, verdict: string, attempts: int, reused: bool}>, 1: array<string, int>}
     */
    private function runConcurrentWorkers(int $leadId, array $workerPlans, string $counterFile): array
    {
        file_put_contents($counterFile, json_encode([], JSON_THROW_ON_ERROR));

        $goFile = $this->tempDirectory.'/'.uniqid('go-', true);
        $readyFiles = [
            'worker-a' => $this->tempDirectory.'/'.uniqid('ready-a-', true),
            'worker-b' => $this->tempDirectory.'/'.uniqid('ready-b-', true),
        ];

        $processes = [
            'worker-a' => $this->buildWorkerProcess($leadId, 'worker-a', $workerPlans['worker-a'], $counterFile, $readyFiles['worker-a'], $goFile),
            'worker-b' => $this->buildWorkerProcess($leadId, 'worker-b', $workerPlans['worker-b'], $counterFile, $readyFiles['worker-b'], $goFile),
        ];

        foreach ($processes as $process) {
            $process->setTimeout(20);
            $process->start();
        }

        $this->waitForFiles(array_values($readyFiles), 5);
        touch($goFile);

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput()."\nSTDOUT:\n".$process->getOutput());
        }

        $results = [];
        foreach ($processes as $workerName => $process) {
            $results[$workerName] = $this->decodeWorkerResult($workerName, $process->getOutput());
        }

        /** @var array<string, int> $counts */
        $counts = json_decode((string) file_get_contents($counterFile), true);

        return [$results, $counts];
    }

    /**
     * @param  array{expectedVerdict: string, expectedAttempts: int}  $workerPlan
     */
    private function buildWorkerProcess(
        int $leadId,
        string $workerName,
        array $workerPlan,
        string $counterFile,
        string $readyFile,
        string $goFile,
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/lead_validation_concurrency_worker.php'),
            $this->databasePath,
            (string) $leadId,
            $workerName,
            $workerPlan['expectedVerdict'],
            (string) $workerPlan['expectedAttempts'],
            $counterFile,
            $readyFile,
            $goFile,
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function waitForFiles(array $paths, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $allPresent = true;

            foreach ($paths as $path) {
                if (! file_exists($path)) {
                    $allPresent = false;
                    break;
                }
            }

            if ($allPresent) {
                return;
            }

            usleep(10000);
        }

        $this->fail('Timed out waiting for worker processes to reach the start barrier.');
    }

    private function useSqliteDatabase(string $databasePath): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $databasePath,
            'database.connections.sqlite.busy_timeout' => 5000,
        ]);

        DB::purge('sqlite');
        $connection = DB::connection('sqlite');
        $connection->statement('PRAGMA busy_timeout = 5000');
    }

    /**
     * @return array{worker: string, verdict: string, attempts: int, reused: bool}
     */
    private function decodeWorkerResult(string $workerName, string $output): array
    {
        $trimmedOutput = trim($output);
        $this->assertNotEmpty($trimmedOutput, sprintf('Worker %s did not produce any JSON output.', $workerName));

        /** @var array{worker: string, verdict: string, attempts: int, reused: bool}|null $decoded */
        $decoded = json_decode($trimmedOutput, true);

        $this->assertIsArray($decoded, sprintf('Worker %s produced non-JSON output: %s', $workerName, $trimmedOutput));

        return $decoded;
    }

    /**
     * @param  array<string, array{expectedVerdict: string, expectedAttempts: int}>  $workerPlans
     * @param  array<string, int>  $counts
     */
    private function assertExactlyOneWorkerReachedTheProvider(array $workerPlans, array $counts): string
    {
        $activeWorkers = array_filter($counts, static fn (int $count): bool => $count > 0);

        $this->assertCount(1, $activeWorkers, 'Exactly one worker should reach the provider for a concurrent duplicate lead.');

        $winningWorker = (string) array_key_first($activeWorkers);
        $this->assertSame(
            $workerPlans[$winningWorker]['expectedAttempts'],
            $activeWorkers[$winningWorker],
            'The winning worker should be the only process that consumed its retry plan.',
        );

        return $winningWorker;
    }
}
