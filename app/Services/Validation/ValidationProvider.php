<?php

namespace App\Services\Validation;

use App\Models\Lead;

interface ValidationProvider
{
    public function validate(Lead $lead): ProviderResponse;

    public function getName(): string;
}
