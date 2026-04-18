<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class SyncInvoicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 300;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(): void
    {
        try {
            Context::add('user', $this->user);

            if (! $this->user->getFoodicsToken()) {
                Log::warning("SyncInvoicesJob: User #{$this->user->id} has no Foodics token.");

                return;
            }

            $orders = app(OrderService::class)->fetchNewOrders();

            foreach ($orders as $order) {
                try {
                    app(SyncOrder::class)->handle($order);
                } catch (\Throwable $e) {
                    Log::error("Failed to sync order: {$e->getMessage()}");
                }
            }
        } finally {
            Cache::forget('sync_in_progress:'.$this->user->id);
        }
    }
}
