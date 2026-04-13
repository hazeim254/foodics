<?php

namespace App\Services\Daftra;

use App\Models\Invoice;

class InvoiceService
{
    public function __construct(protected DaftraApiClient $daftraClient) {}

    public function getInvoice(string $foodicsId): ?array
    {
        $response = $this->daftraClient->get('/api2/invoices', [
            'filter' => ['po_number' => $foodicsId],
        ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('data.0.Invoice');
    }

    public function doesFoodicsInvoiceExistInDaftra(string $id): bool
    {
        return $this->getInvoice((string) $id) !== null;
    }

    public function createInvoice(array $data)
    {
        return $this->daftraClient->post('/api2/invoices', $data)->json('data.id');
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

    public function saveMapping(string $foodicsId, int $daftraId): void
    {
        Invoice::create([
            'user_id' => \Context::get('user')->id,
            'foodics_id' => $foodicsId,
            'daftra_id' => $daftraId,
            'status' => 'synced',
        ]);
    }

    public function createPayment(int $daftraInvoiceId, array $paymentData): void
    {
        $this->daftraClient->post("/api2/invoices/$daftraInvoiceId/payments", $paymentData);
    }
}
