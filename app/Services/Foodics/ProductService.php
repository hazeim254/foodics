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
}
