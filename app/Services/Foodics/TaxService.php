<?php

namespace App\Services\Foodics;

class TaxService
{
    public function __construct(protected FoodicsApiClient $client) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchTaxes(): array
    {
        $allTaxes = [];
        $after = null;
        $hasMore = true;

        while ($hasMore) {
            $params = ['limit' => 50];

            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = $this->client->get('/v5/taxes', $params);
            $response->throw();

            $data = $response->json('data') ?? [];

            if (empty($data)) {
                $hasMore = false;

                continue;
            }

            $allTaxes = array_merge($allTaxes, $data);

            $meta = $response->json('meta') ?? [];
            $cursor = $meta['next_cursor'] ?? null;

            if ($cursor !== null) {
                $after = $cursor;
            } else {
                $hasMore = false;
            }
        }

        return $allTaxes;
    }
}