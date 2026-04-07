<?php

namespace App\Webhooks\Handlers;

use App\Models\WebhookLog;

interface WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void;
}
