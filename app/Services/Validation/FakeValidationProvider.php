<?php

namespace App\Services\Validation;

use App\Models\Lead;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for the fake validation provider.
 */
class FakeValidationProvider implements ValidationProvider
{
    private const RETRYABLE_STATUS_CODES = [429, 503];

    public function __construct(
        private readonly string $endpoint = 'https://fake-validation-api.example.com/validate',
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function validate(Lead $lead): ProviderResponse
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->post($this->endpoint, [
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'country' => $lead->country,
                ]);

            $providerResponse = $this->toProviderResponse($response);
        } catch (ConnectionException) {
            $providerResponse = ProviderResponse::temporaryFailureResponse([
                'reason' => 'timeout',
            ]);
        } catch (Exception) {
            $providerResponse = ProviderResponse::unknownResponse([
                'reason' => 'unexpected_provider_exception',
            ]);
        }

        return $providerResponse;
    }

    public function getName(): string
    {
        return 'fake-provider';
    }

    private function toProviderResponse(Response $response): ProviderResponse
    {
        if ($this->isRetryableStatusCode($response->status())) {
            return ProviderResponse::temporaryFailureResponse([
                'reason' => 'temporary_http_failure',
                'http_status' => $response->status(),
            ]);
        }

        if (! $response->successful()) {
            return ProviderResponse::unknownResponse([
                'reason' => 'unexpected_http_status',
                'http_status' => $response->status(),
            ]);
        }

        $status = $response->json('status');

        if (! is_string($status)) {
            return ProviderResponse::unknownResponse([
                'reason' => 'malformed_response',
                'http_status' => $response->status(),
            ]);
        }

        return $this->mapSuccessfulResponse($status);
    }

    private function isRetryableStatusCode(int $statusCode): bool
    {
        return in_array($statusCode, self::RETRYABLE_STATUS_CODES, true);
    }

    private function translateVerdict(string $status): ValidationVerdict
    {
        return match ($status) {
            'valid' => ValidationVerdict::VALID,
            'invalid' => ValidationVerdict::INVALID,
            default => ValidationVerdict::UNKNOWN,
        };
    }

    private function mapSuccessfulResponse(string $status): ProviderResponse
    {
        $verdict = $this->translateVerdict($status);

        return new ProviderResponse(
            verdict: $verdict,
            metadata: $verdict === ValidationVerdict::UNKNOWN
                ? [
                    'reason' => 'provider_returned_unknown',
                    'provider_status' => $status,
                ] : [],
        );
    }
}
