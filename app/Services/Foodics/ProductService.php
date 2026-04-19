<?php

namespace App\Services\Foodics;

class ProductService
{
    public function __construct(protected FoodicsApiClient $client) {}

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function getProduct(string $productId): array
    {
        $response = $this->client->get("/v5/products/{$productId}");

        $response->throw();

        $product = $response->json('data');
        if (! is_array($product)) {
            throw new \RuntimeException(
                "Foodics product response is missing data for product [{$productId}]: ".$response->body()
            );
        }

        return $product;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchProducts(): array
    {
        $allProducts = [];
        $after = null;
        $hasMore = true;

        while ($hasMore) {
            $params = [
                'limit' => 50,
            ];

            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = $this->client->get('/v5/products', $params);
            $response->throw();

            $data = $response->json('data') ?? [];

            if (empty($data)) {
                $hasMore = false;

                continue;
            }

            $allProducts = array_merge($allProducts, $data);

            $meta = $response->json('meta') ?? [];
            $cursor = $meta['next_cursor'] ?? null;

            if ($cursor !== null) {
                $after = $cursor;
            } else {
                $hasMore = false;
            }
        }

        return $allProducts;
    }
}
