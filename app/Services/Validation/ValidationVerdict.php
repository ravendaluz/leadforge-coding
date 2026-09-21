<?php

namespace App\Services\Validation;

enum ValidationVerdict: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case UNKNOWN = 'unknown';
}
