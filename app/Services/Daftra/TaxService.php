<?php

namespace App\Services\Daftra;

use App\Models\EntityMapping;
use Illuminate\Support\Facades\Context;

class TaxService
{
    public function __construct(protected DaftraApiClient $daftraClient) {}

    public function resolveTaxId(array $foodicsTax): int
    {
        $foodicsId = (string) $foodicsTax['id'];
        $userId = Context::get('user')->id;

        $local = EntityMapping::query()
            ->where('user_id', $userId)
            ->ofType('tax')
            ->where('foodics_id', $foodicsId)
            ->first();

        if ($local !== null) {
            return $local->daftra_id;
        }

        $daftraId = $this->getTax($foodicsTax);
        if ($daftraId !== null) {
            $this->persistTax($userId, $foodicsId, $daftraId, $foodicsTax);

            return $daftraId;
        }

        $daftraId = $this->createTax($foodicsTax);
        $this->persistTax($userId, $foodicsId, $daftraId, $foodicsTax);

        return $daftraId;
    }

    public function getTax(array $foodicsTax): ?int
    {
        $taxName = (string) ($foodicsTax['name'] ?? '');
        if ($taxName === '') {
            return null;
        }

        $taxValue = (float) ($foodicsTax['rate'] ?? 0);

        $listResponse = $this->daftraClient->get('/api2/taxes.json', [
            'filter' => ['name' => $taxName],
            'limit' => 100,
        ]);

        if (! $listResponse->successful()) {
            throw new \RuntimeException(
                'Daftra tax list request failed: HTTP '.$listResponse->status().' '.$listResponse->body()
            );
        }

        $rows = $listResponse->json('data') ?? [];
        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            $rowName = (string) ($row['Tax']['name'] ?? '');
            $rowValue = (float) ($row['Tax']['value'] ?? 0);

            if ($rowName === $taxName && $rowValue === $taxValue) {
                return $this->daftraTaxIdFromListRow($row);
            }
        }

        return null;
    }

    public function createTax(array $foodicsTax): int
    {
        $payload = $this->buildCreatePayload($foodicsTax);
        $createResponse = $this->daftraClient->post('/api2/taxes.json', $payload);

        if ($createResponse->status() !== 202) {
            throw new \RuntimeException(
                'Daftra tax creation failed: HTTP '.$createResponse->status().' '.$createResponse->body()
            );
        }

        $newId = $createResponse->json('id');
        if ($newId === null || $newId === '') {
            throw new \RuntimeException(
                'Daftra tax creation response missing id: '.$createResponse->body()
            );
        }

        return (int) $newId;
    }

    private function persistTax(int $userId, string $foodicsId, int $daftraId, array $foodicsTax): void
    {
        EntityMapping::query()->create([
            'user_id' => $userId,
            'type' => 'tax',
            'foodics_id' => $foodicsId,
            'daftra_id' => $daftraId,
            'metadata' => [
                'name' => $foodicsTax['name'] ?? null,
                'rate' => $foodicsTax['rate'] ?? null,
            ],
            'status' => 'synced',
        ]);
    }

    private function daftraTaxIdFromListRow(array $row): int
    {
        $id = $row['Tax']['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \RuntimeException('Daftra tax list row missing Tax.id.');
        }

        return (int) $id;
    }

    private function buildCreatePayload(array $foodicsTax): array
    {
        $tax = [
            'name' => (string) ($foodicsTax['name'] ?? 'Tax'),
            'value' => (float) ($foodicsTax['rate'] ?? 0),
            'included' => 0,
        ];

        return ['Tax' => $tax];
    }
}
