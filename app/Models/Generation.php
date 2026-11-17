<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_generation_id',
        'prompt',
        'size',
        'quality',
        'reference_image_urls',
        'status',
        'markup_percentage',
        'rates',
        'estimated_input_text_tokens',
        'estimated_input_image_tokens',
        'estimated_output_tokens',
        'estimated_reasoning_tokens',
        'actual_input_text_tokens',
        'actual_input_image_tokens',
        'actual_output_tokens',
        'actual_reasoning_tokens',
        'actual_total_tokens',
        'estimated_provider_cost',
        'estimated_user_cost',
        'actual_provider_cost',
        'actual_user_cost',
        'image_url',
        'image_url_expires_at',
        'image_path',
        'error_code',
        'error_message',
        'provider_metadata',
        'submission_attempts',
        'submitted_at',
        'started_at',
        'completed_at',
        'last_synced_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_image_urls' => 'array',
            'rates' => 'array',
            'provider_metadata' => 'array',
            'markup_percentage' => 'decimal:4',
            'estimated_provider_cost' => 'decimal:12',
            'estimated_user_cost' => 'decimal:12',
            'actual_provider_cost' => 'decimal:12',
            'actual_user_cost' => 'decimal:12',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayImageUrl(): ?string
    {
        if ($this->image_path) {
            return Storage::disk('public')->url($this->image_path);
        }

        if (! $this->image_url) {
            return null;
        }

        if ($this->image_url_expires_at?->isPast()) {
            return null;
        }

        return $this->image_url;
    }
}
