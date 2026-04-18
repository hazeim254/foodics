<?php

namespace App\Services\Daftra;

use App\Exceptions\DaftraInvoiceCreationFailedException;
use App\Models\Invoice;
use Illuminate\Support\Facades\Context;

class InvoiceService
{
    public function __construct(protected DaftraApiClient $daftraClient) {}

    public function getInvoice(string $foodicsId): ?array
    {
        $response = $this->daftraClient->get('/api2/invoices', [
            'custom_field' => $foodicsId,
            'custom_field_label' => 'Foodics ID',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Daftra invoice list request failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $rows = $response->json('data') ?? [];
        if ($rows === []) {
            return null;
        }

        return $rows[0]['Invoice'] ?? null;
    }

    public function doesFoodicsInvoiceExistInDaftra(string $id): bool
    {
        return $this->getInvoice($id) !== null;
    }

    public function createInvoice(array $data): int
    {
        $response = $this->daftraClient->post('/api2/invoices', $data);

        if ($response->failed()) {
            throw new DaftraInvoiceCreationFailedException(
                message: 'Daftra invoice creation failed: HTTP '.$response->status(),
                responseBody: $response->body(),
            );
        }

        $newId = $response->json('id');
        if ($newId === null || $newId === '') {
            throw new DaftraInvoiceCreationFailedException(
                message: 'Daftra invoice creation response missing id.',
                responseBody: $response->body(),
            );
        }

        return (int) $newId;
    }

    public function updateInvoice(int $id, array $data): bool
    {
        $result = $this->daftraClient->put("/api2/invoices/$id", $data);

        return $result->successful();
    }

    public function deleteInvoice(int $id): bool
    {
        $result = $this->daftraClient->delete("/api2/invoices/$id");

        return $result->successful();
    }

    public function saveMapping(string $foodicsId, int $daftraId, string $foodicsReference): void
    {
        Invoice::query()->create([
            'user_id' => Context::get('user')->id,
            'foodics_id' => $foodicsId,
            'daftra_id' => $daftraId,
            'foodics_reference' => $foodicsReference,
            'status' => 'synced',
        ]);
    }

    public function createPayment(array $data): void
    {
        $this->daftraClient->post('/api2/invoice_payments', $data);
    }
}
