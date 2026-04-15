<?php

namespace App\Services\Foodics;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Context;

/**
 * Service to fetch new orders from Foodics API using cursor-based pagination.
 */
class OrderService
{
    public function __construct(protected FoodicsApiClient $client) {}

    /**
     * Fetch all new orders for the current user.
     */
    public function fetchNewOrders(): array
    {
        /** @var User $user */
        $user = Context::get('user');

        $referenceAfter = Invoice::where('user_id', $user->id)
            ->max('foodics_reference');

        $allOrders = [];
        $hasMore = true;

        while ($hasMore) {
            $result = $this->fetchPage($referenceAfter);
            $orders = $result['orders'];
            dd($orders);

            if (empty($orders)) {
                $hasMore = false;

                continue;
            }

            $allOrders = array_merge($allOrders, $orders);
            $referenceAfter = $result['next_reference'];
        }

        return $allOrders;
    }

    /**
     * Fetch a single page of orders.
     */
    protected function fetchPage(?string $referenceAfter = null): array
    {
        $params = [
            'sort' => 'reference',
            'include' => 'payments,charges,customer,products',
            'limit' => 50,
        ];

        if ($referenceAfter !== null) {
            $params['filter[reference_after]'] = $referenceAfter;
        }

        $response = $this->client->get('/v5/orders', $params);

        $orders = $response->json('data') ?? [];

        // Determine next reference from last order, if any
        $nextReference = null;
        if (! empty($orders)) {
            $lastOrder = end($orders);
            $nextReference = $lastOrder['reference'] ?? null;
        }

        return ['orders' => $orders, 'next_reference' => $nextReference];
    }

    /**
     * Fetch a single order by ID from the Foodics API.
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->client->get("/orders/{$orderId}", [
            'include' => 'payments,charges,customer,products',
        ]);

        $response->throw();

        return $response->json('order');
    }
}
