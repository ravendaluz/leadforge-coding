<?php

namespace App\Services\Validation\Repositories;

use App\Models\Lead;

/**
 * Persists validation results for a lead/provider pair.
 */
interface LeadValidationResultRepository
{
    /**
     * @return array{verdict: string, attempts: int, metadata: array<string, mixed>}|null
     */
    public function findForLeadAndProvider(Lead $lead, string $providerName): ?array;

    /**
     * @return array{verdict: string, attempts: int, metadata: array<string, mixed>}|null
     */
    public function findForLeadAndProviderForUpdate(Lead $lead, string $providerName): ?array;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createPendingResult(
        Lead $lead,
        string $providerName,
        array $metadata = [],
    ): void;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function saveResult(
        Lead $lead,
        string $providerName,
        string $verdict,
        int $attempts,
        array $metadata = [],
    ): void;
}
