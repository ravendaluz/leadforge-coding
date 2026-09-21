<?php

namespace App\Services\Validation;

readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialDelayMs = 100,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1');
        }
        if ($initialDelayMs < 0) {
            throw new \InvalidArgumentException('initialDelayMs must be non-negative');
        }
    }

    public function calculateDelayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('attempt must be at least 1');
        }

        return $this->initialDelayMs * (2 ** ($attempt - 1));
    }

    public function shouldAttempt(int $attempt): bool
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('attempt must be at least 1');
        }

        return $attempt <= $this->maxAttempts;
    }

    public function hasExhaustedAttempts(int $attempt): bool
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('attempt must be at least 1');
        }

        return $attempt >= $this->maxAttempts;
    }
}
