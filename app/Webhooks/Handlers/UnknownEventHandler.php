<?php

namespace App\Webhooks\Handlers;

use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class UnknownEventHandler implements WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void
    {
        Log::warning('Unknown webhook event type', [
            'webhook_log_id' => $webhookLog->id,
            'event' => $webhookLog->event,
            'business_reference' => $webhookLog->business_reference,
        ]);

        // For unknown events, we'll mark as processed but log a warning
        // This prevents infinite retries for events we don't handle
    }
}
