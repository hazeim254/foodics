<?php

namespace App\Services\Daftra;

use App\Models\EntityMapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class PaymentMethodService
{
    public function __construct(protected DaftraApiClient $daftraClient) {}

    public function resolvePaymentMethod(array $foodicsPaymentMethod): int
    {
        $foodicsId = (string) $foodicsPaymentMethod['id'];
        $userId = Context::get('user')->id;

        $local = EntityMapping::query()
            ->where('user_id', $userId)
            ->ofType('payment_method')
            ->where('foodics_id', $foodicsId)
            ->first();

        if ($local !== null) {
            return $local->daftra_id;
        }

        $daftraId = $this->getPaymentMethod($foodicsPaymentMethod);
        if ($daftraId !== null) {
            $this->persistPaymentMethod($userId, $foodicsId, $daftraId, $foodicsPaymentMethod);

            return $daftraId;
        }

        $daftraId = $this->createPaymentMethod($foodicsPaymentMethod);
        $this->persistPaymentMethod($userId, $foodicsId, $daftraId, $foodicsPaymentMethod);

        return $daftraId;
    }

    public function getPaymentMethods(): array
    {
        $listResponse = $this->daftraClient->get('/api2/site_payment_gateway/list/1.json');

        if (! $listResponse->successful()) {
            throw new \RuntimeException(
                'Daftra payment method list request failed: HTTP '.$listResponse->status().' '.$listResponse->body()
            );
        }

        return $listResponse->json('data') ?? [];
    }

    public function createPaymentMethod(array $foodicsPaymentMethod): int
    {
        $payload = $this->buildCreatePayload($foodicsPaymentMethod);
        $createResponse = $this->daftraClient->post('/api2/site_payment_gateway.json', $payload);

        if ($createResponse->status() !== 201) {
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

        return (int) $newId;
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
            ],
            'status' => 'synced',
        ]);
    }

    public function getPaymentMethod(array $foodicsPaymentMethod): ?int
    {
        $label = (string) ($foodicsPaymentMethod['name'] ?? '');

        $list = $this->getPaymentMethods();
        foreach ($list as $row) {
            $gatewayLabel = $row['SitePaymentGateway']['label'] ?? null;
            if ($gatewayLabel === $label) {
                $id = $row['SitePaymentGateway']['id'] ?? null;
                if ($id !== null && $id !== '') {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    private function buildCreatePayload(array $foodicsPaymentMethod): array
    {
        $name = (string) ($foodicsPaymentMethod['name'] ?? 'Payment');
        $slug = Str::slug($name, '_');

        $gateway = [
            'payment_gateway' => $slug,
            'label' => $name,
            'manually_added' => 1,
            'active' => 1,
        ];

        return ['SitePaymentGateway' => $gateway];
    }
}
