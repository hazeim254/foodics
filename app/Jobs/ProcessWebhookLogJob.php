<?php

namespace App\Jobs;

use App\Enums\WebhookStatus;
use App\Models\WebhookLog;
use App\Webhooks\Handlers\OrderCancelledHandler;
use App\Webhooks\Handlers\OrderCreatedHandler;
use App\Webhooks\Handlers\OrderUpdatedHandler;
use App\Webhooks\Handlers\UnknownEventHandler;
use App\Webhooks\Handlers\WebhookHandlerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWebhookLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $timeout = 180;

    public int $maxExceptions = 3;

//    public $afterCommit = true;

    public function __construct(public int $webhookLogId) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $webhookLog = WebhookLog::query()
                ->lockForUpdate()
                ->find($this->webhookLogId);

            if (!$webhookLog) {
                Log::warning('WebhookLog not found, job may have been processed already', [
                    'webhook_log_id' => $this->webhookLogId,
                ]);
                return;
            }

            if ($webhookLog->status === WebhookStatus::Processed) {
                Log::info('WebhookLog already processed, skipping', [
                    'webhook_log_id' => $this->webhookLogId,
                    'processed_at' => $webhookLog->processed_at,
                ]);
                return;
            }

            // Don't retry if already failed permanently (after max retries)
            if ($webhookLog->status === WebhookStatus::Failed && $this->attempts() >= $this->tries) {
                Log::warning('WebhookLog already failed permanently, skipping retry', [
                    'webhook_log_id' => $this->webhookLogId,
                    'attempts' => $this->attempts(),
                ]);
                return;
            }

            try {
                // Mark as processing (optional: add a 'processing' status if needed)
                // For now, we'll keep it as pending until successful

                // Process the webhook based on event type
                $this->processWebhook($webhookLog);

                // Mark as processed
                $webhookLog->update([
                    'status' => WebhookStatus::Processed,
                    'processed_at' => now(),
                    'error_message' => null, // Clear any previous error
                ]);

                Log::info('WebhookLog processed successfully', [
                    'webhook_log_id' => $this->webhookLogId,
                    'event' => $webhookLog->event,
                    'business_reference' => $webhookLog->business_reference,
                ]);
            } catch (Throwable $e) {
                // Log the error but don't update status yet
                // Status will be updated in the failed() method if all retries are exhausted
                Log::error('Error processing WebhookLog', [
                    'webhook_log_id' => $this->webhookLogId,
                    'event' => $webhookLog->event ?? null,
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Re-throw to trigger retry mechanism
                throw $e;
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception) {
            $webhookLog = WebhookLog::query()
                ->lockForUpdate()
                ->find($this->webhookLogId);

            if (!$webhookLog) {
                Log::error('WebhookLog not found in failed() method', [
                    'webhook_log_id' => $this->webhookLogId,
                ]);
                return;
            }

            // Mark as failed permanently
            $webhookLog->update([
                'status' => WebhookStatus::Failed,
                'error_message' => $exception ? $exception->getMessage() : 'Job failed after maximum retries',
            ]);

            Log::error('WebhookLog processing failed permanently', [
                'webhook_log_id' => $this->webhookLogId,
                'event' => $webhookLog->event,
                'business_reference' => $webhookLog->business_reference,
                'total_attempts' => $this->attempts(),
                'error' => $exception?->getMessage(),
            ]);

            // TODO: Consider sending alert/notification here for critical failures
            // Example: Send to monitoring service, email admin, etc.
        });
    }

    /**
     * Process the webhook based on its event type.
     */
    protected function processWebhook(WebhookLog $webhookLog): void
    {
        $event = $webhookLog->event;
        $payload = $webhookLog->payload;

        $handler = $this->getHandlerForEvent($event);
        $handler->handle($webhookLog, $payload);
    }

    /**
     * Get the appropriate handler for the given event type.
     */
    protected function getHandlerForEvent(string $event): WebhookHandlerInterface
    {
        return match ($event) {
            'order.created' => new OrderCreatedHandler,
            'order.updated' => new OrderUpdatedHandler,
            'order.cancelled' => new OrderCancelledHandler,
            default => new UnknownEventHandler,
        };
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }
}
