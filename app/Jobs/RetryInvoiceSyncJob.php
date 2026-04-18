<?php

namespace App\Jobs;

use App\Enums\InvoiceSyncStatus;
use App\Models\Invoice;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class RetryInvoiceSyncJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public Invoice $invoice) {}

    public function handle(): void
    {
        $user = $this->invoice->user;
        Context::add('user', $user);

        if (! $user->getFoodicsToken()) {
            Log::warning("RetryInvoiceSyncJob: User #{$user->id} has no Foodics token.");
            $this->invoice->update(['status' => InvoiceSyncStatus::Failed]);

            return;
        }

        $order = app(OrderService::class)->getOrder($this->invoice->foodics_id);

        app(SyncOrder::class)->handle($order);
    }
}
