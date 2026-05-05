<?php

namespace App\Services\Foodics;

class BranchService
{
    public function __construct(protected FoodicsApiClient $client) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchBranches(): array
    {
        $allBranches = [];
        $after = null;
        $hasMore = true;

        while ($hasMore) {
            $params = ['limit' => 50];

            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = $this->client->get('/v5/branches', $params);
            $response->throw();

            $data = $response->json('data') ?? [];

            if (empty($data)) {
                $hasMore = false;

                continue;
            }

            $allBranches = array_merge($allBranches, $data);

            $meta = $response->json('meta') ?? [];
            $cursor = $meta['next_cursor'] ?? null;

            if ($cursor !== null) {
                $after = $cursor;
            } else {
                $hasMore = false;
            }
        }

        return $allBranches;
    }
}