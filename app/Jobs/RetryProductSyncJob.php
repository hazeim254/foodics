<?php

namespace App\Jobs;

use App\Enums\ProductSyncStatus;
use App\Models\Product;
use App\Services\Foodics\ProductService as FoodicsProductService;
use App\Services\SyncProductService;
use App\Services\UserContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class RetryProductSyncJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public Product $product) {}

    public function handle(): void
    {
        $user = $this->product->user;
        app(UserContext::class)->set($user);

        if (! $user->getFoodicsToken()) {
            Log::warning("RetryProductSyncJob: User #{$user->id} has no Foodics token.");
            $this->product->update(['status' => ProductSyncStatus::Failed]);

            return;
        }

        $foodicsProduct = app(FoodicsProductService::class)->getProduct($this->product->foodics_id);

        app(SyncProductService::class)->handle($foodicsProduct);
    }
}
