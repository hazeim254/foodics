<?php

namespace App\Webhooks\Handlers;

use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class OrderCreatedHandler implements WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void
    {
        // TODO: Implement order creation logic
        // Example: Sync order to Daftra, create invoice, etc.
        Log::info('Processing order.created event', [
            'webhook_log_id' => $webhookLog->id,
            'order_id' => $webhookLog->order_id,
            'business_reference' => $webhookLog->business_reference,
        ]);

        // Placeholder: Add actual processing logic here
        // This will be implemented later when integrating with Daftra services
    }
}
