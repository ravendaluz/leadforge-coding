<?php

namespace App\Services\Validation;

/**
 * Result returned to the caller after a validation attempt.
 */
readonly class ValidationResult
{
    public function __construct(
        public int $leadId,
        public ValidationVerdict $verdict,
        public string $providerName,
        public int $attempts,
        public bool $reused,
    ) {}
}
