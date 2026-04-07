<?php

namespace App\Services\Daftra;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class InvoiceService
{
    private string $apiUrl;

    private string $appId;

    private string $appSecret;

    public function __construct()
    {
        $this->apiUrl = config('services.daftra.api_url', 'https://api.daftra.com/api/v1');
        $this->appId = config('services.daftra.app_id');
        $this->appSecret = config('services.daftra.app_secret');
    }

    private function httpClient(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->withToken($this->getAccessToken())
            ->withHeaders([
                'X-App-Id' => $this->appId,
                'X-App-Secret' => $this->appSecret,
            ]);
    }

    private function getAccessToken(): string
    {
        // In a real implementation, you would cache this token and refresh it when needed
        // For now, we'll assume the token is stored or can be obtained from the user's provider token
        return '';
    }

    /**
     * Create a new invoice in Daftra
     *
     * @param array $invoiceData Invoice data including customer_id, items, totals, etc.
     * @return array The created invoice data from Daftra API
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function createInvoice(array $invoiceData): array
    {
        return $this->httpClient()
            ->post("{$this->apiUrl}/invoices", $invoiceData)
            ->throw()
            ->json();
    }

    /**
     * Update an existing invoice in Daftra
     *
     * @param int|string $invoiceId The ID of the invoice to update
     * @param array $invoiceData Invoice data to update
     * @return array The updated invoice data from Daftra API
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function updateInvoice(int|string $invoiceId, array $invoiceData): array
    {
        return $this->httpClient()
            ->put("{$this->apiUrl}/invoices/{$invoiceId}", $invoiceData)
            ->throw()
            ->json();
    }

    /**
     * Delete an invoice from Daftra
     *
     * @param int|string $invoiceId The ID of the invoice to delete
     * @return bool True if deletion was successful
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function deleteInvoice(int|string $invoiceId): bool
    {
        $response = $this->httpClient()
            ->delete("{$this->apiUrl}/invoices/{$invoiceId}")
            ->throw();

        return $response->successful();
    }

    /**
     * Get a specific invoice from Daftra
     *
     * @param int|string $invoiceId The ID of the invoice to retrieve
     * @return array The invoice data from Daftra API
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function getInvoice(int|string $invoiceId): array
    {
        return $this->httpClient()
            ->get("{$this->apiUrl}/invoices/{$invoiceId}")
            ->throw()
            ->json();
    }

    /**
     * List invoices from Daftra with optional filters
     *
     * @param array $filters Optional filters (e.g., page, limit, status, customer_id)
     * @return array The list of invoices from Daftra API
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function listInvoices(array $filters = []): array
    {
        return $this->httpClient()
            ->get("{$this->apiUrl}/invoices", $filters)
            ->throw()
            ->json();
    }

    /**
     * Send an invoice to a customer
     *
     * @param int|string $invoiceId The ID of the invoice to send
     * @param array $options Additional options (e.g., email, send_method)
     * @return array The response from Daftra API
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function sendInvoice(int|string $invoiceId, array $options = []): array
    {
        return $this->httpClient()
            ->post("{$this->apiUrl}/invoices/{$invoiceId}/send", $options)
            ->throw()
            ->json();
    }

    /**
     * Mark an invoice as paid
     *
     * @param int|string $invoiceId The ID of the invoice to mark as paid
     * @param array $paymentData Payment details (amount, payment_method, payment_date, etc.)
     * @return array The updated invoice data from Daftra API
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function markAsPaid(int|string $invoiceId, array $paymentData): array
    {
        return $this->httpClient()
            ->post("{$this->apiUrl}/invoices/{$invoiceId}/payments", $paymentData)
            ->throw()
            ->json();
    }
}
