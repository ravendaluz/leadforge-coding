<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadValidationResult extends Model
{
    protected $fillable = [
        'lead_id',
        'provider_name',
        'verdict',
        'attempts',
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<Lead, LeadValidationResult>
     */
    public function lead(): BelongsTo
    {
        // @phpstan-ignore-next-line
        return $this->belongsTo(Lead::class);
    }
}
