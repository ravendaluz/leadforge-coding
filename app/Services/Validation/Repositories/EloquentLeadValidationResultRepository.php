<?php

namespace App\Services\Validation\Repositories;

use App\Models\Lead;
use App\Models\LeadValidationResult as LeadValidationResultModel;

/**
 * Stores validation results in the database.
 */
class EloquentLeadValidationResultRepository implements LeadValidationResultRepository
{
    /**
     * @return array{verdict: string, attempts: int, metadata: array<string, mixed>}|null
     */
    public function findForLeadAndProvider(Lead $lead, string $providerName): ?array
    {
        return $this->findResult($lead, $providerName);
    }

    /**
     * @return array{verdict: string, attempts: int, metadata: array<string, mixed>}|null
     */
    public function findForLeadAndProviderForUpdate(Lead $lead, string $providerName): ?array
    {
        return $this->findResult($lead, $providerName, true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createPendingResult(
        Lead $lead,
        string $providerName,
        array $metadata = [],
    ): void {
        LeadValidationResultModel::create([
            'lead_id' => $lead->id,
            'provider_name' => $providerName,
            'verdict' => 'unknown',
            'attempts' => 0,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array{verdict: string, attempts: int, metadata: array<string, mixed>}|null
     */
    private function findResult(Lead $lead, string $providerName, bool $forUpdate = false): ?array
    {
        $result = LeadValidationResultModel::where('lead_id', $lead->id)
            ->where('provider_name', $providerName)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $result) {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($result->metadata) ? $result->metadata : [];

        return [
            'verdict' => (string) $result->verdict,
            'attempts' => (int) $result->attempts,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function saveResult(
        Lead $lead,
        string $providerName,
        string $verdict,
        int $attempts,
        array $metadata = [],
    ): void {
        LeadValidationResultModel::where('lead_id', $lead->id)
            ->where('provider_name', $providerName)
            ->update([
                'verdict' => $verdict,
                'attempts' => $attempts,
                'metadata' => $metadata,
            ]);
    }
}
