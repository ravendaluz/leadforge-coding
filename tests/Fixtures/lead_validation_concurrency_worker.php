<?php

declare(strict_types=1);

use App\Models\Lead;
use App\Services\Validation\LeadValidationService;
use App\Services\Validation\ProviderResponse;
use App\Services\Validation\Repositories\EloquentLeadValidationResultRepository;
use App\Services\Validation\RetryPolicy;
use App\Services\Validation\ValidationProvider;
use App\Services\Validation\ValidationVerdict;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc < 9) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(1);
}

[, $databasePath, $leadId, $workerName, $expectedVerdict, $expectedAttempts, $counterFile, $readyFile, $goFile] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$databasePath);
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $databasePath;
$_SERVER['APP_ENV'] = 'testing';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $databasePath;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.busy_timeout' => 5000,
]);

$connection = DB::connection('sqlite');
$connection->statement('PRAGMA busy_timeout = 5000');

$lead = Lead::query()->findOrFail((int) $leadId);
$expectedAttemptCount = (int) $expectedAttempts;
$verdict = ValidationVerdict::from($expectedVerdict);

$provider = new class($workerName, $counterFile, $verdict, $expectedAttemptCount) implements ValidationProvider
{
    private int $attempts = 0;

    public function __construct(
        private readonly string $workerName,
        private readonly string $counterFile,
        private readonly ValidationVerdict $expectedVerdict,
        private readonly int $expectedAttempts,
    ) {}

    public function validate(Lead $lead): ProviderResponse
    {
        $this->attempts++;
        $this->incrementWorkerCallCount();

        usleep(150000);

        if ($this->attempts < $this->expectedAttempts) {
            return ProviderResponse::temporaryFailureResponse();
        }

        return new ProviderResponse(verdict: $this->expectedVerdict);
    }

    public function getName(): string
    {
        return 'fake-provider';
    }

    private function incrementWorkerCallCount(): void
    {
        $handle = fopen($this->counterFile, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open provider counter file.');
        }

        try {
            flock($handle, LOCK_EX);

            $counts = $this->readCounts($handle);
            $counts[$this->workerName] = (int) ($counts[$this->workerName] ?? 0) + 1;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($counts, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array<string, int>
     */
    private function readCounts($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
};

touch($readyFile);

$deadline = microtime(true) + 10;
while (! file_exists($goFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for start signal.\n");
        exit(2);
    }

    usleep(10000);
}

$service = new LeadValidationService(
    $provider,
    new EloquentLeadValidationResultRepository,
    $connection,
    new RetryPolicy(maxAttempts: max($expectedAttemptCount, 1), initialDelayMs: 10),
);

$result = $service->validate($lead);

echo json_encode([
    'worker' => $workerName,
    'verdict' => $result->verdict->value,
    'attempts' => $result->attempts,
    'reused' => $result->reused,
], JSON_THROW_ON_ERROR);
