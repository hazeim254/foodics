<?php

namespace App\Services;

use App\Enums\WebhookStatus;
use App\Jobs\ProcessWebhookLogJob;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WebhookLogService
{
    /**
     * Log a webhook request to the database and dispatch processing job.
     *
     * @param Request $request
     * @return WebhookLog
     * @throws \Exception
     */
    public function log(Request $request): WebhookLog
    {
        $payload = $request->all();

        if (!isset($payload['event'])) {
            throw new \Exception('Missing required field: event');
        }

        if (!isset($payload['timestamp'])) {
            throw new \Exception('Missing required field: timestamp');
        }

        $businessReference = data_get($payload, 'business.reference');
        $orderId = data_get($payload, 'order.id');
        $orderReference = data_get($payload, 'order.reference');

        $timestamp = is_numeric($payload['timestamp'])
            ? Carbon::createFromTimestamp($payload['timestamp'])
            : Carbon::parse($payload['timestamp']);

        $webhookLog = WebhookLog::query()->create([
            'event' => $payload['event'],
            'timestamp' => $timestamp,
            'payload' => $payload,
            'signature' => $request->header('X-Signature'),
            'status' => WebhookStatus::Pending,
            'business_reference' => $businessReference ? (int) $businessReference : null,
            'order_id' => $orderId,
            'order_reference' => $orderReference ? (int) $orderReference : null,
        ]);

        // Dispatch job to process the webhook asynchronously
        ProcessWebhookLogJob::dispatch($webhookLog->id);

        return $webhookLog;
    }
}
