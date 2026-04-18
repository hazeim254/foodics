<?php

namespace App\Services\Daftra;

use App\Models\EntityMapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class PaymentMethodService
{
    /** @var array<string, array{id: int, slug: string}>|null */
    private ?array $prefetchedGatewaysBySlug = null;

    public function __construct(protected DaftraApiClient $daftraClient) {}

    /**
     * Load Daftra payment gateways once for the current order sync. Call before resolving
     * multiple Foodics payment methods so the list endpoint is hit at most once per batch.
     */
    public function beginPaymentMethodBatch(): void
    {
        $this->prefetchedGatewaysBySlug = $this->getPaymentGatewaysKeyedBySlug();
    }

    public function endPaymentMethodBatch(): void
    {
        $this->prefetchedGatewaysBySlug = null;
    }

    /**
     * @return array<string, array{id: int, slug: string}>
     */
    public function getPaymentGatewaysKeyedBySlug(): array
    {
        $bySlug = [];

        foreach ($this->getPaymentMethods() as $row) {
            $gatewaySlug = $row['payment_gateway'] ?? null;
            if ($gatewaySlug === null || $gatewaySlug === '') {
                continue;
            }
            $key = (string) $gatewaySlug;
            $id = $row['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            if (! isset($bySlug[$key])) {
                $bySlug[$key] = [
                    'id' => (int) $id,
                    'slug' => $key,
                ];
            }
        }

        return $bySlug;
    }

    public function resolvePaymentMethod(array $foodicsPaymentMethod): string
    {
        $foodicsId = (string) $foodicsPaymentMethod['id'];
        $userId = Context::get('user')->id;
        $canonicalSlug = $this->paymentGatewaySlug($foodicsPaymentMethod);

        $local = EntityMapping::query()
            ->where('user_id', $userId)
            ->ofType('payment_method')
            ->where('foodics_id', $foodicsId)
            ->first();

        if ($local !== null) {
            $stored = $local->metadata['payment_gateway'] ?? null;

            return $stored !== null && $stored !== '' ? (string) $stored : $canonicalSlug;
        }

        $found = $this->getPaymentMethod($foodicsPaymentMethod);
        if ($found !== null) {
            $this->persistPaymentMethod($userId, $foodicsId, $found['id'], $foodicsPaymentMethod);

            return $found['slug'];
        }

        $created = $this->createPaymentMethodInDaftra($foodicsPaymentMethod);
        $this->persistPaymentMethod($userId, $foodicsId, $created['id'], $foodicsPaymentMethod);

        if ($this->prefetchedGatewaysBySlug !== null) {
            $this->prefetchedGatewaysBySlug[$created['slug']] = [
                'id' => $created['id'],
                'slug' => $created['slug'],
            ];
        }

        return $created['slug'];
    }

    public function getPaymentMethods(): array
    {
        $listResponse = $this->daftraClient->get('/v2/api/entity/site_payment_gateway/list?per_page=100');

        if (! $listResponse->successful()) {
            throw new \RuntimeException(
                'Daftra payment method list request failed: HTTP '.$listResponse->status().' '.$listResponse->body()
            );
        }

        return $listResponse->json('data') ?? [];
    }

    public function createPaymentMethod(array $foodicsPaymentMethod): string
    {
        return $this->createPaymentMethodInDaftra($foodicsPaymentMethod)['slug'];
    }

    public function persistPaymentMethod(int $userId, string $foodicsId, int $daftraId, array $foodicsPaymentMethod): void
    {
        EntityMapping::query()->create([
            'user_id' => $userId,
            'type' => 'payment_method',
            'foodics_id' => $foodicsId,
            'daftra_id' => $daftraId,
            'metadata' => [
                'name' => $foodicsPaymentMethod['name'] ?? null,
                'code' => $foodicsPaymentMethod['code'] ?? null,
                'payment_gateway' => $this->paymentGatewaySlug($foodicsPaymentMethod),
            ],
            'status' => 'synced',
        ]);
    }

    /**
     * @return array{id: int, slug: string}|null
     */
    public function getPaymentMethod(array $foodicsPaymentMethod): ?array
    {
        $expectedSlug = $this->paymentGatewaySlug($foodicsPaymentMethod);
        $bySlug = $this->prefetchedGatewaysBySlug ?? $this->getPaymentGatewaysKeyedBySlug();

        return $bySlug[$expectedSlug] ?? null;
    }

    /**
     * @return array{id: int, slug: string}
     */
    private function createPaymentMethodInDaftra(array $foodicsPaymentMethod): array
    {
        $payload = $this->buildCreatePayload($foodicsPaymentMethod);
        $createResponse = $this->daftraClient->post('/v2/api/entity/site_payment_gateway', $payload);

        if ($createResponse->failed()) {
            throw new \RuntimeException(
                'Daftra payment method creation failed: HTTP '.$createResponse->status().' '.$createResponse->body()
            );
        }

        $newId = $createResponse->json('id');
        if ($newId === null || $newId === '') {
            throw new \RuntimeException(
                'Daftra payment method creation response missing id: '.$createResponse->body()
            );
        }

        $slug = $payload['payment_gateway'] ?? null;
        if ($slug === null || $slug === '') {
            throw new \RuntimeException('Daftra payment method create payload missing payment_gateway slug.');
        }

        return [
            'id' => (int) $newId,
            'slug' => (string) $slug,
        ];
    }

    /**
     * @return array{payment_gateway: string, label: string, manually_added: int, active: int}
     */
    private function buildCreatePayload(array $foodicsPaymentMethod): array
    {
        $name = (string) ($foodicsPaymentMethod['name'] ?? 'Payment');
        $slug = $this->paymentGatewaySlug($foodicsPaymentMethod);

        return [
            'payment_gateway' => $slug,
            'slug' => $slug,
            'label' => $name,
            'manually_added' => 1,
            'active' => 1,
        ];
    }

    private function paymentGatewaySlug(array $foodicsPaymentMethod): string
    {
        $name = (string) ($foodicsPaymentMethod['name'] ?? 'Payment');

        return Str::slug($name, '_');
    }
}
