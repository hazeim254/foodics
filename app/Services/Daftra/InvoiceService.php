<?php

namespace App\Services\Daftra;

use App\Exceptions\DaftraInvoiceCreationFailedException;
use App\Exceptions\DaftraPaymentCreationFailedException;

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

    /**
     * Fetch existing payments for a Daftra invoice. Used to decide whether
     * a retry of a sync needs to re-post payments or can skip them entirely.
     *
     * @see https://docs.daftara.dev/15115306e0
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvoicePayments(int $daftraInvoiceId): array
    {
        $response = $this->daftraClient->get('/v2/api/entity/invoice_payment/list', [
            'filter[invoice_id]' => $daftraInvoiceId,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Daftra invoice payments list request failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        return $response->json('data') ?? [];
    }

    public function createPayment(array $data): void
    {
        $response = $this->daftraClient->post('/api2/invoice_payments', $data);

        if ($response->failed()) {
            throw new DaftraPaymentCreationFailedException(
                message: 'Daftra invoice payment creation failed: HTTP '.$response->status(),
                responseBody: $response->body(),
            );
        }
    }
}
