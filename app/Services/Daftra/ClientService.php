<?php

namespace App\Services\Daftra;

use App\Exceptions\DaftraClientCreationFailedException;
use App\Models\Client;
use Illuminate\Support\Str;

class ClientService
{
    public function __construct(protected DaftraApiClient $daftraClient) {}

    /**
     * Look up an existing client in Daftra using Foodics customer data (`client_number` is the Foodics customer `id`).
     *
     * @param  array<string, mixed>  $foodicsCustomer
     *
     * @throws \RuntimeException When the Daftra list request fails.
     */
    public function getClient(array $foodicsCustomer): ?int
    {
        $clientNumber = (string) $foodicsCustomer['id'];

        $listResponse = $this->daftraClient->get('/api2/clients.json', [
            'filter' => [
                'client_number' => $clientNumber,
            ],
        ]);

        if (! $listResponse->successful()) {
            throw new \RuntimeException(
                'Daftra client list request failed: HTTP '.$listResponse->status().' '.$listResponse->body()
            );
        }

        $rows = $listResponse->json('data') ?? [];
        if ($rows === []) {
            return null;
        }

        return $this->daftraClientIdFromListRow($rows[0]);
    }

    /**
     * Create a client in Daftra from Foodics customer data.
     *
     * @param  array<string, mixed>  $foodicsCustomer
     *
     * @throws DaftraClientCreationFailedException
     */
    public function createClient(array $foodicsCustomer): int
    {
        $foodicsId = (string) $foodicsCustomer['id'];
        $payload = $this->buildCreatePayload($foodicsCustomer, $foodicsId);
        $createResponse = $this->daftraClient->post('/api2/clients.json', $payload);

        if ($createResponse->status() !== 202) {
            throw new DaftraClientCreationFailedException(
                message: 'Daftra client creation failed: HTTP '.$createResponse->status(),
                responseBody: $createResponse->body(),
            );
        }

        $newId = $createResponse->json('id');
        if ($newId === null || $newId === '') {
            throw new DaftraClientCreationFailedException(
                message: 'Daftra client creation response missing id.',
                responseBody: $createResponse->body(),
            );
        }

        return (int) $newId;
    }

    public function updateClient() {}

    public function deleteClient() {}

    /**
     * @param  array<string, mixed>  $foodicsCustomer
     *
     * @throws DaftraClientCreationFailedException
     */
    public function getClientUsingFoodicsData(array $foodicsCustomer): int
    {
        $foodicsId = (string) $foodicsCustomer['id'];
        $userId = \Context::get('user')->id;

        $local = Client::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $foodicsId)
            ->first();

        if ($local !== null) {
            return $local->daftra_id;
        }

        $daftraId = $this->getClient($foodicsCustomer);
        if ($daftraId !== null) {
            $this->persistClient($userId, $foodicsId, $daftraId);

            return $daftraId;
        }

        $daftraId = $this->createClient($foodicsCustomer);
        $this->persistClient($userId, $foodicsId, $daftraId);

        return $daftraId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function daftraClientIdFromListRow(array $row): int
    {
        $id = $row['Client']['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \RuntimeException('Daftra client list row missing Client.id.');
        }

        return (int) $id;
    }

    private function persistClient(int $userId, string $foodicsId, int $daftraId): void
    {
        Client::query()->create([
            'user_id' => $userId,
            'foodics_id' => $foodicsId,
            'daftra_id' => $daftraId,
            'status' => 'synced',
        ]);
    }

    /**
     * Build Daftra `Client` attributes from Foodics customer data. Key shape aligns with the API;
     * see `json-stubs/daftra/create-client.json` for a full example payload (reference only — not loaded at runtime).
     *
     * @param  array<string, mixed>  $foodicsCustomer
     * @return array{Client: array<string, mixed>}
     */
    private function buildCreatePayload(array $foodicsCustomer, string $foodicsId): array
    {
        [$firstName, $lastName] = $this->splitCustomerName((string) ($foodicsCustomer['name'] ?? ''));
        $email = $this->resolveCustomerEmail($foodicsCustomer, $foodicsId);
        $phone1 = $this->formatFoodicsPhone($foodicsCustomer);

        $client = [
            'is_offline' => true,
            'client_number' => $foodicsId,
            'staff_id' => 0,
            'business_name' => (string) ($foodicsCustomer['name'] ?? 'Customer'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Str::random(32),
            'type' => 2,
        ];

        if ($phone1 !== '') {
            $client['phone1'] = $phone1;
        }

        if (! empty($foodicsCustomer['birth_date'])) {
            $client['birth_date'] = (string) $foodicsCustomer['birth_date'];
        }

        return ['Client' => $client];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitCustomerName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['-', '-'];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [
            $parts[0],
            $parts[1] ?? '-',
        ];
    }

    /**
     * @param  array<string, mixed>  $foodicsCustomer
     */
    private function resolveCustomerEmail(array $foodicsCustomer, string $foodicsId): string
    {
        $email = isset($foodicsCustomer['email']) ? trim((string) $foodicsCustomer['email']) : '';

        return $email !== '' ? $email : "foodics-{$foodicsId}@clients.invalid";
    }

    /**
     * @param  array<string, mixed>  $foodicsCustomer
     */
    private function formatFoodicsPhone(array $foodicsCustomer): string
    {
        $phone = isset($foodicsCustomer['phone']) ? trim((string) $foodicsCustomer['phone']) : '';
        if ($phone === '') {
            return '';
        }

        $dial = $foodicsCustomer['dial_code'] ?? '';
        if ($dial !== '' && $dial !== null) {
            return (string) $dial.$phone;
        }

        return $phone;
    }
}
