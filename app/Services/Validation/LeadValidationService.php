<?php

namespace App\Services\Validation;

use App\Models\Lead;
use App\Services\Validation\Repositories\LeadValidationResultRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Validates a lead and reuses prior provider results.
 *
 * @phpstan-type StoredValidationResult array{verdict: string, attempts: int, metadata: array<string, mixed>}
 */
class LeadValidationService
{
    private readonly RetryPolicy $retryPolicy;

    public function __construct(
        private readonly ValidationProvider $provider,
        private readonly LeadValidationResultRepository $resultRepository,
        private readonly ConnectionInterface $connection,
        RetryPolicy $retryPolicy = new RetryPolicy,
    ) {
        $this->retryPolicy = $retryPolicy;
    }

    public function validate(Lead $lead): ValidationResult
    {
        $providerName = $this->provider->getName();

        try {
            return $this->connection->transaction(function () use ($lead, $providerName): ValidationResult {
                return $this->performValidation($lead, $providerName);
            });
        } catch (QueryException $e) {
            return $this->recoverFromConcurrentReservation($lead, $providerName, $e);
        }
    }

    private function performValidation(Lead $lead, string $providerName): ValidationResult
    {
        $lead = Lead::query()
            ->whereKey($lead->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        Log::info('Lead validation started', [
            'lead_id' => $lead->id,
            'provider' => $providerName,
        ]);

        /** @var StoredValidationResult|null $existingResult */
        $existingResult = $this->resultRepository->findForLeadAndProviderForUpdate($lead, $providerName);

        if ($existingResult !== null && ! $this->isPending($existingResult)) {
            Log::info('Lead validation result reused', [
                'lead_id' => $lead->id,
                'provider' => $providerName,
                'verdict' => $existingResult['verdict'],
                'attempts' => $existingResult['attempts'],
            ]);

            return $this->buildStoredResult($lead, $providerName, $existingResult);
        }

        if ($existingResult === null) {
            $existingResult = $this->createPendingResult($lead, $providerName);
        }

        $attempts = (int) $existingResult['attempts'];
        $verdict = ValidationVerdict::UNKNOWN;
        $exhausted = false;

        /** @var array<string, mixed> $metadata */
        $metadata = $existingResult['metadata'];
        unset($metadata['pending'], $metadata['exhausted']);

        for ($attempt = 1; $this->retryPolicy->shouldAttempt($attempt); $attempt++) {
            $attempts++;

            if ($attempt > 1) {
                $delayMs = $this->retryPolicy->calculateDelayMs($attempt);
                Log::info('Retrying lead validation', [
                    'lead_id' => $lead->id,
                    'provider' => $providerName,
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                ]);
                usleep($delayMs * 1000);
            }

            $response = $this->provider->validate($lead);
            $metadata = $response->metadata;

            if (! $response->isTemporaryFailure) {
                $verdict = $response->verdict;
                break;
            }

            if ($this->retryPolicy->hasExhaustedAttempts($attempt)) {
                $exhausted = true;
                Log::warning('Lead validation retries exhausted', [
                    'lead_id' => $lead->id,
                    'provider' => $providerName,
                    'attempts' => $attempts,
                ]);
            }
        }

        if ($exhausted) {
            $metadata['exhausted'] = true;
        }

        $this->resultRepository->saveResult(
            $lead,
            $providerName,
            $verdict->value,
            $attempts,
            $metadata,
        );

        Log::info('Lead validation completed', [
            'lead_id' => $lead->id,
            'provider' => $providerName,
            'verdict' => $verdict->value,
            'attempts' => $attempts,
            'exhausted' => $metadata['exhausted'] ?? false,
        ]);

        return new ValidationResult(
            leadId: (int) $lead->id,
            verdict: $verdict,
            providerName: $providerName,
            attempts: $attempts,
            reused: false,
        );
    }

    /**
     * @param  StoredValidationResult  $storedResult
     */
    private function isPending(array $storedResult): bool
    {
        return (bool) ($storedResult['metadata']['pending'] ?? false);
    }

    /**
     * @return StoredValidationResult
     */
    private function createPendingResult(Lead $lead, string $providerName): array
    {
        $metadata = ['pending' => true];

        $this->resultRepository->createPendingResult($lead, $providerName, $metadata);

        return [
            'verdict' => ValidationVerdict::UNKNOWN->value,
            'attempts' => 0,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  StoredValidationResult  $storedResult
     */
    private function buildStoredResult(Lead $lead, string $providerName, array $storedResult): ValidationResult
    {
        return new ValidationResult(
            leadId: (int) $lead->id,
            verdict: ValidationVerdict::from((string) $storedResult['verdict']),
            providerName: $providerName,
            attempts: (int) $storedResult['attempts'],
            reused: true,
        );
    }

    private function recoverFromConcurrentReservation(Lead $lead, string $providerName, QueryException $e): ValidationResult
    {
        if (! $this->isConcurrentReservationException($e)) {
            throw $e;
        }

        $existingResult = $this->waitForStoredResult($lead, $providerName);

        if ($existingResult === null) {
            throw $e;
        }

        Log::info('Lead validation result reused after concurrent reservation', [
            'lead_id' => $lead->id,
            'provider' => $providerName,
            'verdict' => $existingResult['verdict'],
            'attempts' => $existingResult['attempts'],
        ]);

        return $this->buildStoredResult($lead, $providerName, $existingResult);
    }

    /**
     * @return StoredValidationResult|null
     */
    private function waitForStoredResult(Lead $lead, string $providerName): ?array
    {
        $deadline = microtime(true) + 5;

        do {
            $existingResult = $this->resultRepository->findForLeadAndProvider($lead, $providerName);

            if ($existingResult !== null && ! $this->isPending($existingResult)) {
                return $existingResult;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function isDuplicateResultException(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();

        return in_array((string) $sqlState, ['23000', '23505'], true);
    }

    private function isDatabaseLockedException(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'database is locked');
    }

    private function isConcurrentReservationException(QueryException $e): bool
    {
        return $this->isDuplicateResultException($e) || $this->isDatabaseLockedException($e);
    }
}
