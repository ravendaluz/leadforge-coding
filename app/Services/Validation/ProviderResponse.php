<?php

namespace App\Services\Validation;

/**
 * Provider response mapped to domain-level verdicts.
 */
readonly class ProviderResponse
{
    public function __construct(
        public ValidationVerdict $verdict,
        public bool $isTemporaryFailure = false,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function unknownResponse(array $metadata = []): self
    {
        return new self(
            verdict: ValidationVerdict::UNKNOWN,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function temporaryFailureResponse(array $metadata = []): self
    {
        return new self(
            verdict: ValidationVerdict::UNKNOWN,
            isTemporaryFailure: true,
            metadata: $metadata,
        );
    }
}
