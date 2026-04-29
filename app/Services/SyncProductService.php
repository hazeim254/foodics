<?php

namespace App\Services;

use App\Enums\ProductSyncStatus;
use App\Exceptions\ProductAlreadyExistsException;
use App\Models\Product;
use App\Services\Daftra\ProductService as DaftraProductService;
use Illuminate\Support\Facades\Context;
use Throwable;

class SyncProductService
{
    public function __construct(
        protected DaftraProductService $daftraProductService,
    ) {}

    public function handle(array $foodicsProduct): void
    {
        try {
            $this->skipIfAlreadySynced((string) $foodicsProduct['id']);
        } catch (ProductAlreadyExistsException $e) {
            return;
        }

        $product = $this->createOrUpdatePending($foodicsProduct);

        try {
            $this->runSync($foodicsProduct, $product);
        } catch (Throwable $e) {
            $product->update(['status' => ProductSyncStatus::Failed]);

            throw $e;
        }
    }

    /**
     * @throws ProductAlreadyExistsException
     */
    protected function skipIfAlreadySynced(string $foodicsId): void
    {
        $userId = Context::get('user')?->id;

        $blocking = Product::query()
            ->where('user_id', $userId)
            ->whereIn('status', [ProductSyncStatus::Pending, ProductSyncStatus::Synced])
            ->where('foodics_id', $foodicsId)
            ->exists();

        throw_if($blocking, new ProductAlreadyExistsException('Product already synced or in progress locally'));
    }

    protected function createOrUpdatePending(array $foodicsProduct): Product
    {
        $userId = Context::get('user')?->id;
        $foodicsId = (string) $foodicsProduct['id'];

        $product = Product::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $foodicsId)
            ->first();

        $pendingData = [
            'foodics_name' => (string) ($foodicsProduct['name'] ?? 'Unknown Product'),
            'foodics_sku' => isset($foodicsProduct['sku']) && trim((string) $foodicsProduct['sku']) !== ''
                                        ? trim((string) $foodicsProduct['sku'])
                                        : null,
            'price' => (float) ($foodicsProduct['price'] ?? 0),
            'status' => ProductSyncStatus::Pending,
            'foodics_metadata' => $this->buildFoodicsMetadata($foodicsProduct),
        ];

        if ($product !== null) {
            $product->fill($pendingData)->save();

            return $product;
        }

        return Product::query()->create(array_merge([
            'user_id' => $userId,
            'foodics_id' => $foodicsId,
            'daftra_id' => null,
        ], $pendingData));
    }

    protected function runSync(array $foodicsProduct, Product $product): void
    {
        $daftraId = $this->daftraProductService->getProductByFoodicsData($foodicsProduct);

        $product->update([
            'daftra_id' => $daftraId,
            'status' => ProductSyncStatus::Synced,
            'daftra_metadata' => $this->buildDaftraMetadata($daftraId),
        ]);
    }

    protected function buildFoodicsMetadata(array $foodicsProduct): array
    {
        return [
            'cost' => isset($foodicsProduct['cost']) ? (float) $foodicsProduct['cost'] : null,
            'is_active' => (bool) ($foodicsProduct['is_active'] ?? true),
            'barcode' => (string) ($foodicsProduct['barcode'] ?? ''),
            'category' => $foodicsProduct['category']['name'] ?? null,
            'tax_group' => $foodicsProduct['tax_group']['reference'] ?? null,
        ];
    }

    protected function buildDaftraMetadata(int $daftraId): array
    {
        return [
            'id' => $daftraId,
        ];
    }
}
