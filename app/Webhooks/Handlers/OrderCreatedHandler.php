<?php

namespace App\Webhooks\Handlers;

use App\Models\WebhookLog;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use App\Services\UserContext;
use Illuminate\Support\Facades\Log;

class OrderCreatedHandler implements WebhookHandlerInterface
{
    public function handle(WebhookLog $webhookLog, array $payload): void
    {
        $orderId = data_get($payload, 'order.id');

        if (! $orderId) {
            Log::warning('OrderCreatedHandler: Missing order ID in webhook payload', [
                'webhook_log_id' => $webhookLog->id,
            ]);

            return;
        }

        $user = $webhookLog->user;

        if (! $user) {
            Log::warning('OrderCreatedHandler: No user associated with webhook', [
                'webhook_log_id' => $webhookLog->id,
                'business_reference' => $webhookLog->business_reference,
            ]);

            return;
        }

        if (! $user->getFoodicsToken()) {
            Log::warning('OrderCreatedHandler: User has no Foodics token', [
                'user_id' => $user->id,
                'webhook_log_id' => $webhookLog->id,
            ]);

            return;
        }

        app(UserContext::class)->set($user);

        $order = app(OrderService::class)->getOrder($orderId);

        if (empty($order)) {
            throw new \RuntimeException("Failed to fetch order {$orderId} from Foodics API");
        }

        app(SyncOrder::class)->handle($order);
    }
}
