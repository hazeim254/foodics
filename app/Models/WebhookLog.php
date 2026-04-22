<?php

namespace App\Models;

use App\Enums\WebhookStatus;
use App\Models\Builders\WebhookLogQueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'user_id',
        'event',
        'timestamp',
        'payload',
        'signature',
        'status',
        'error_message',
        'processed_at',
        'business_reference',
        'order_id',
        'order_reference',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'timestamp' => 'datetime',
            'processed_at' => 'datetime',
            'status' => WebhookStatus::class,
        ];
    }

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder($query): WebhookLogQueryBuilder
    {
        return new WebhookLogQueryBuilder($query);
    }
}
