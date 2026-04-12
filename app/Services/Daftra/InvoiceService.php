<?php

namespace App\Services\Daftra;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class InvoiceService
{
    private DaftraApiClient $daftraClient;

    public function __construct()
    {
        $this->daftraClient = new DaftraApiClient(\Context::get('user'));
    }

    public function getInvoice(int $id): array
    {
        return $this->daftraClient->get("/api2/invoices/$id")->json();
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
}
