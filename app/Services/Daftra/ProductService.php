<?php

namespace App\Services\Daftra;

use App\Exceptions\DaftraProductCreationFailedException;
use App\Models\Product;
use Illuminate\Support\Facades\Context;

class ProductService
{
    public function __construct(protected DaftraApiClient $daftraClient) {}

    /**
     * @param  array<string, mixed>  $foodicsProduct
     *
     * @throws DaftraProductCreationFailedException
     * @throws \RuntimeException
     */
    public function getProductByFoodicsData(array $foodicsProduct): int
    {
        $foodicsId = (string) $foodicsProduct['id'];
        $userId = Context::get('user')->id;

        $local = Product::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $foodicsId)
            ->first();

        if ($local !== null) {
            return $local->daftra_id;
        }

        $daftraId = $this->getProduct($foodicsProduct);
        if ($daftraId !== null) {
            $this->persistProduct($userId, $foodicsId, $daftraId);

            return $daftraId;
        }

        $daftraId = $this->createProduct($foodicsProduct);
        $this->persistProduct($userId, $foodicsId, $daftraId);

        return $daftraId;
    }

    /**
     * @param  array<string, mixed>  $foodicsProduct
     *
     * @throws \RuntimeException
     */
    public function getProduct(array $foodicsProduct): ?int
    {
        $foodicsId = (string) $foodicsProduct['id'];
        $productCode = $this->resolveProductCode($foodicsProduct, $foodicsId);

        $listResponse = $this->daftraClient->get('/api2/products.json', [
            'filter' => [
                'product_code' => $productCode,
            ],
        ]);

        if (! $listResponse->successful()) {
            throw new \RuntimeException(
                'Daftra product list request failed: HTTP '.$listResponse->status().' '.$listResponse->body()
            );
        }

        $rows = $listResponse->json('data') ?? [];
        if ($rows === []) {
            return null;
        }

        return $this->daftraProductIdFromListRow($rows[0]);
    }

    /**
     * @param  array<string, mixed>  $foodicsProduct
     *
     * @throws DaftraProductCreationFailedException
     */
    public function createProduct(array $foodicsProduct): int
    {
        $foodicsId = (string) $foodicsProduct['id'];
        $payload = $this->buildCreatePayload($foodicsProduct, $foodicsId);
        $createResponse = $this->daftraClient->post('/api2/products.json', $payload);

        if ($createResponse->status() !== 202) {
            throw new DaftraProductCreationFailedException(
                message: 'Daftra product creation failed: HTTP '.$createResponse->status(),
                responseBody: $createResponse->body(),
            );
        }

        $newId = $createResponse->json('id');
        if ($newId === null || $newId === '') {
            throw new DaftraProductCreationFailedException(
                message: 'Daftra product creation response missing id.',
                responseBody: $createResponse->body(),
            );
        }

        return (int) $newId;
    }

    public function updateProduct() {}

    public function deleteProduct() {}

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws \RuntimeException
     */
    private function daftraProductIdFromListRow(array $row): int
    {
        $id = $row['Product']['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \RuntimeException('Daftra product list row missing Product.id.');
        }

        return (int) $id;
    }

    private function persistProduct(int $userId, string $foodicsId, int $daftraId): void
    {
        Product::query()->create([
            'user_id' => $userId,
            'foodics_id' => $foodicsId,
            'daftra_id' => $daftraId,
            'status' => 'synced',
        ]);
    }

    /**
     * @param  array<string, mixed>  $foodicsProduct
     * @return array{Product: array<string, mixed>}
     */
    private function buildCreatePayload(array $foodicsProduct, string $foodicsId): array
    {
        $productCode = $this->resolveProductCode($foodicsProduct, $foodicsId);

        $product = [
            'staff_id' => 0,
            'name' => (string) ($foodicsProduct['name'] ?? 'Foodics Product'),
            'description' => (string) ($foodicsProduct['description'] ?? ''),
            'unit_price' => (float) ($foodicsProduct['price'] ?? 0),
            'buy_price' => isset($foodicsProduct['cost']) ? (float) $foodicsProduct['cost'] : null,
            'product_code' => $productCode,
            'barcode' => (string) ($foodicsProduct['barcode'] ?? ''),
            'type' => 1,
            'status' => (bool) ($foodicsProduct['is_active'] ?? true)? 0 : 1,
        ];

        if ($product['buy_price'] === null) {
            unset($product['buy_price']);
        }

        return ['Product' => $product];
    }

    /**
     * @param  array<string, mixed>  $foodicsProduct
     */
    private function resolveProductCode(array $foodicsProduct, string $foodicsId): string
    {
        $sku = isset($foodicsProduct['sku']) ? trim((string) $foodicsProduct['sku']) : '';

        return $sku !== '' ? $sku : $foodicsId;
    }
}
