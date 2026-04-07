<?php

namespace App\Webhooks\Handlers;

use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class OrderUpdatedHandler implements WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void
    {
        // TODO: Implement order update logic
        Log::info('Processing order.updated event', [
            'webhook_log_id' => $webhookLog->id,
            'order_id' => $webhookLog->order_id,
            'business_reference' => $webhookLog->business_reference,
        ]);
    }
}
