<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Foodics\ProductService as FoodicsProductService;
use App\Services\SyncProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class SyncProductsJob implements ShouldBeUnique, ShouldQueue
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
                Log::warning("SyncProductsJob: User #{$this->user->id} has no Foodics token.");

                return;
            }

            $products = app(FoodicsProductService::class)->fetchProducts();

            foreach ($products as $productData) {
                try {
                    app(SyncProductService::class)->handle($productData);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } finally {
            Cache::forget('sync_products_in_progress:'.$this->user->id);
        }
    }
}
