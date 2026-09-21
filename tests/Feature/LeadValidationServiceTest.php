<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadValidationResult;
use App\Services\Validation\FakeValidationProvider;
use App\Services\Validation\LeadValidationService;
use App\Services\Validation\ProviderResponse;
use App\Services\Validation\Repositories\EloquentLeadValidationResultRepository;
use App\Services\Validation\RetryPolicy;
use App\Services\Validation\ValidationProvider;
use App\Services\Validation\ValidationVerdict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = Lead::create([
            'email' => 'janedoe@lead.com',
            'phone' => '+1234000000',
            'country' => 'US',
        ]);
    }

    private function createService(int $maxAttempts = 3, int $initialDelayMs = 100): LeadValidationService
    {
        return $this->createServiceWithProvider(
            new FakeValidationProvider,
            maxAttempts: $maxAttempts,
            initialDelayMs: $initialDelayMs
        );
    }

    private function createServiceWithProvider(
        ValidationProvider $provider,
        int $maxAttempts = 3,
        int $initialDelayMs = 100): LeadValidationService
    {
        $repository = new EloquentLeadValidationResultRepository;
        $retryPolicy = new RetryPolicy(
            maxAttempts: $maxAttempts,
            initialDelayMs: $initialDelayMs,
        );

        return new LeadValidationService(
            $provider,
            $repository,
            DB::connection(),
            $retryPolicy,
        );
    }

    public function test_valid_provider_response(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'valid']),
        ]);

        $service = $this->createService();
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::VALID, $result->verdict);
        $this->assertEquals(1, $result->attempts);
        $this->assertFalse($result->reused);
    }

    public function test_invalid_provider_response(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'invalid']),
        ]);

        $service = $this->createService();
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::INVALID, $result->verdict);
        $this->assertEquals(1, $result->attempts);
        $this->assertFalse($result->reused);
    }

    public function test_unknown_provider_response(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'unknown']),
        ]);

        $service = $this->createService();
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $result->verdict);
        $this->assertEquals(1, $result->attempts);
        $this->assertFalse($result->reused);
        $this->assertDatabaseHas('lead_validation_results', [
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::UNKNOWN->value,
            'attempts' => 1,
        ]);
    }

    public function test_temporary_failure_then_success(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'error'], 503)
                ->push(['status' => 'valid']),
        ]);

        $service = $this->createService(initialDelayMs: 1);
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::VALID, $result->verdict);
        $this->assertEquals(2, $result->attempts);
        $this->assertFalse($result->reused);
    }

    public function test_exhausted_retries(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'error'], 429),
        ]);

        $service = $this->createService(initialDelayMs: 1);
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $result->verdict);
        $this->assertEquals(3, $result->attempts);
        $this->assertFalse($result->reused);

        $storedResult = LeadValidationResult::query()->sole();
        $this->assertSame(3, $storedResult->attempts);
        $this->assertSame('temporary_http_failure', $storedResult->metadata['reason'] ?? null);
        $this->assertSame(429, $storedResult->metadata['http_status'] ?? null);
        $this->assertTrue((bool) ($storedResult->metadata['exhausted'] ?? false));
    }

    public function test_reuses_completed_result(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'valid']),
        ]);

        $service = $this->createService();
        $firstResult = $service->validate($this->lead);
        $secondResult = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::VALID, $firstResult->verdict);
        $this->assertFalse($firstResult->reused);

        $this->assertEquals(ValidationVerdict::VALID, $secondResult->verdict);
        $this->assertTrue($secondResult->reused);
        $this->assertEquals(1, $secondResult->attempts);
        $this->assertDatabaseCount('lead_validation_results', 1);
        $this->assertDatabaseHas('lead_validation_results', [
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::VALID->value,
            'attempts' => 1,
        ]);

        Http::assertSentCount(1);
    }

    public function test_malformed_provider_response(): void
    {
        Http::fake([
            '*' => Http::response(['unexpected' => 'data']),
        ]);

        $service = $this->createService();
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $result->verdict);
        $this->assertEquals(1, $result->attempts);

        $storedResult = LeadValidationResult::query()->sole();
        $this->assertSame('malformed_response', $storedResult->metadata['reason'] ?? null);
        $this->assertSame(200, $storedResult->metadata['http_status'] ?? null);
    }

    public function test_reuses_completed_unknown_result_without_second_provider_call(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'unknown']),
        ]);

        $service = $this->createService();
        $firstResult = $service->validate($this->lead);
        $secondResult = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $firstResult->verdict);
        $this->assertFalse($firstResult->reused);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $secondResult->verdict);
        $this->assertTrue($secondResult->reused);
        $this->assertEquals(1, $secondResult->attempts);
        $this->assertDatabaseCount('lead_validation_results', 1);

        Http::assertSentCount(1);
    }

    public function test_http_503_is_treated_as_temporary_failure(): void
    {
        Http::fake([
            '*' => Http::response([], 503),
        ]);

        $service = $this->createService(maxAttempts: 2, initialDelayMs: 1);
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $result->verdict);
        $this->assertEquals(2, $result->attempts);
    }

    public function test_connection_timeout_is_retried(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timeout');
        });

        $service = $this->createService(maxAttempts: 2, initialDelayMs: 1);
        $result = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $result->verdict);
        $this->assertEquals(2, $result->attempts);
    }

    public function test_completed_unknown_result_is_reused_instead_of_revalidated(): void
    {
        LeadValidationResult::create([
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::UNKNOWN->value,
            'attempts' => 3,
            'metadata' => ['exhausted' => true],
        ]);

        Http::fake([
            '*' => Http::response(['status' => 'valid']),
        ]);

        $service = $this->createService(initialDelayMs: 1);
        $secondResult = $service->validate($this->lead);

        $this->assertEquals(ValidationVerdict::UNKNOWN, $secondResult->verdict);
        $this->assertEquals(3, $secondResult->attempts);
        $this->assertTrue($secondResult->reused);
        $this->assertDatabaseCount('lead_validation_results', 1);
        $this->assertDatabaseHas('lead_validation_results', [
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::UNKNOWN->value,
            'attempts' => 3,
        ]);

        Http::assertSentCount(0);
    }

    public function test_concurrent_workers_do_not_duplicate_provider_calls_for_same_lead(): void
    {
        $provider = new class implements ValidationProvider
        {
            public bool $called = false;

            public function validate(Lead $lead): ProviderResponse
            {
                $this->called = true;

                return new ProviderResponse(verdict: ValidationVerdict::INVALID);
            }

            public function getName(): string
            {
                return 'fake-provider';
            }
        };

        LeadValidationResult::create([
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::VALID->value,
            'attempts' => 1,
            'metadata' => [],
        ]);

        $service = $this->createServiceWithProvider($provider);
        $result = $service->validate($this->lead);

        $this->assertTrue($result->reused);
        $this->assertFalse($provider->called, 'A completed result should be reused before the provider is called again.');
        $this->assertDatabaseCount('lead_validation_results', 1);
    }

    public function test_database_unique_constraint_prevents_duplicate_results_for_same_lead_and_provider(): void
    {
        LeadValidationResult::create([
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::VALID->value,
            'attempts' => 1,
            'metadata' => [],
        ]);

        $this->assertDatabaseCount('lead_validation_results', 1);

        $this->expectException(QueryException::class);

        LeadValidationResult::create([
            'lead_id' => $this->lead->id,
            'provider_name' => 'fake-provider',
            'verdict' => ValidationVerdict::INVALID->value,
            'attempts' => 1,
            'metadata' => [],
        ]);
    }
}
