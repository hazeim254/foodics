<?php

namespace App\Models;

use App\Enums\WebhookStatus;
use App\Models\Builders\WebhookLogQueryBuilder;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
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
