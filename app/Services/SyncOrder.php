<?php

namespace App\Services;

use App\Enums\InvoiceSyncStatus;
use App\Enums\SettingKey;
use App\Exceptions\InvoiceAlreadyExistsException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\PaymentMethodService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use Illuminate\Support\Facades\Context;
use Throwable;

class SyncOrder
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected ProductService $productService,
        protected ClientService $clientService,
        protected TaxService $taxService,
        protected PaymentMethodService $paymentMethodService,
    ) {}

    /** @var array<string, int> */
    protected array $taxMap = [];

    /** @var array<string, string> */
    protected array $paymentMethodMap = [];

    /**
     * A sample of the array structure of foodics order
     * and Daftra Invoice are found in see section.
     *
     * @see json-stubs/foodics/get-order.json
     * @see json-stubs/daftra/create-invoice.json
     */
    public function handle(array $order): void
    {
        try {
            $this->skipIfAlreadySynced($order['id'], $order['reference']);
        } catch (InvoiceAlreadyExistsException $e) {
            return;
        }

        $invoice = $this->createPendingInvoice($order);

        try {
            $this->runSync($order, $invoice);
        } catch (Throwable $e) {
            $invoice->update(['status' => InvoiceSyncStatus::Failed]);

            throw $e;
        }
    }

    protected function runSync(array $order, Invoice $invoice): void
    {
        $this->taxMap = [];
        $this->resolveUniqueTaxes($order);

        $this->paymentMethodMap = [];
        $this->resolveUniquePaymentMethods($order);

        $daftraInvoiceId = $this->resolveDaftraInvoiceId($order, $invoice);

        if ($invoice->daftra_id !== $daftraInvoiceId) {
            $invoice->update(['daftra_id' => $daftraInvoiceId]);
        }

        $this->syncPaymentsIfMissing($order['payments'] ?? [], $daftraInvoiceId);

        $daftraInvoice = $this->invoiceService->getInvoice($order['id']);

        if ($daftraInvoice !== null) {
            $invoice->update([
                'daftra_metadata' => [
                    'no' => $daftraInvoice['no'] ?? null,
                ],
            ]);
        }

        $invoice->update(['status' => InvoiceSyncStatus::Synced]);
    }

    /**
     * Resolve the Daftra invoice id to use for this order, preferring an
     * existing id on the local row, then an invoice already present on
     * Daftra, and finally creating a new one.
     */
    protected function resolveDaftraInvoiceId(array $order, Invoice $invoice): int
    {
        if ($invoice->daftra_id !== null) {
            return (int) $invoice->daftra_id;
        }

        $existing = $this->invoiceService->getInvoice($order['id']);
        if (! empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $invoiceItems = $this->getInvoiceItems($order['products']);
        $invoiceItems = $this->addChargeInvoiceItems($invoiceItems, $order['charges'] ?? []);

        $clientId = null;
        if (! empty($order['customer'])) {
            $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
        }

        if (! $clientId) {
            $clientId = $this->resolveDefaultClientId();
        }

        $invoiceData = [
            'Invoice' => [
                'po_number' => $order['id'],
                'client_id' => $clientId,
                'date' => $order['business_date'],
                'discount_amount' => $order['discount_amount'] ?? 0,
                'notes' => $order['kitchen_notes'] ?? null,
            ],
            'InvoiceItem' => $invoiceItems,
        ];

        return $this->invoiceService->createInvoice($invoiceData);
    }

    public function getInvoiceItems($products): array
    {
        $invoiceItems = [];
        foreach ($products as $orderProduct) {
            $foodicsProductId = $this->resolveFoodicsProductId($orderProduct);
            $embeddedProduct = is_array($orderProduct['product'] ?? null) ? $orderProduct['product'] : [];
            $sku = trim((string) ($embeddedProduct['sku'] ?? ($orderProduct['sku'] ?? '')));
            $enrichedProduct = array_merge($orderProduct, [
                'id' => $embeddedProduct['id'] ?? $foodicsProductId,
                'name' => $embeddedProduct['name'] ?? ($orderProduct['name'] ?? 'Foodics Product'),
                'sku' => $sku !== '' ? $sku : $foodicsProductId,
                'description' => $embeddedProduct['description'] ?? ($orderProduct['description'] ?? ''),
                'barcode' => $embeddedProduct['barcode'] ?? ($orderProduct['barcode'] ?? null),
                'price' => $embeddedProduct['price'] ?? ($orderProduct['price'] ?? null),
                'cost' => $embeddedProduct['cost'] ?? ($orderProduct['cost'] ?? null),
                'is_active' => $embeddedProduct['is_active'] ?? ($orderProduct['is_active'] ?? true),
            ]);

            $daftraProductId = $this->productService->getProductByFoodicsData($enrichedProduct);

            $taxes = $orderProduct['taxes'] ?? [];
            $daftraTaxIds = collect($taxes)
                ->pluck('id')
                ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
                ->filter()
                ->values()
                ->take(2);

            $invoiceItems[] = [
                'product_id' => $daftraProductId,
                'item' => $enrichedProduct['name'] ?? 'Foodics Product',
                'quantity' => $orderProduct['quantity'],
                'unit_price' => $orderProduct['unit_price'],
                'discount' => $orderProduct['discount_amount'] ?? 0,
                'discount_type' => $orderProduct['discount_type'] ?? 2,
                'tax1' => $daftraTaxIds->get(0),
                'tax2' => $daftraTaxIds->get(1),
            ];
        }

        return $invoiceItems;
    }

    public function addChargeInvoiceItems(array $invoiceItems, array $charges): array
    {
        foreach ($charges as $charge) {
            $taxes = $charge['taxes'] ?? [];
            $daftraTaxIds = collect($taxes)
                ->pluck('id')
                ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
                ->filter()
                ->values()
                ->take(2);

            $invoiceItems[] = [
                'item' => $charge['charge']['name'],
                'quantity' => 1,
                'unit_price' => $charge['amount'],
                'discount' => 0,
                'discount_type' => 2,
                'tax1' => $daftraTaxIds->get(0),
                'tax2' => $daftraTaxIds->get(1),
            ];
        }

        return $invoiceItems;
    }

    protected function resolveUniqueTaxes(array $order): void
    {
        $allTaxes = collect();

        foreach ($order['products'] ?? [] as $product) {
            $allTaxes = $allTaxes->merge($product['taxes'] ?? []);
            foreach ($product['options'] ?? [] as $option) {
                $allTaxes = $allTaxes->merge($option['taxes'] ?? []);
            }
        }

        foreach ($order['charges'] ?? [] as $charge) {
            $allTaxes = $allTaxes->merge($charge['taxes'] ?? []);
        }

        $uniqueTaxes = $allTaxes->unique('id');
        foreach ($uniqueTaxes as $tax) {
            $foodicsId = (string) $tax['id'];
            if (! isset($this->taxMap[$foodicsId])) {
                $this->taxMap[$foodicsId] = $this->taxService->resolveTaxId($tax);
            }
        }
    }

    protected function resolveUniquePaymentMethods(array $order): void
    {
        $this->paymentMethodService->beginPaymentMethodBatch();

        try {
            foreach ($order['payments'] ?? [] as $payment) {
                $foodicsPaymentMethod = $payment['payment_method'] ?? [];
                $foodicsId = $foodicsPaymentMethod['id'] ?? '';
                if ($foodicsId !== '' && ! isset($this->paymentMethodMap[$foodicsId])) {
                    $this->paymentMethodMap[$foodicsId] = $this->paymentMethodService->resolvePaymentMethod($foodicsPaymentMethod);
                }
            }
        } finally {
            $this->paymentMethodService->endPaymentMethodBatch();
        }
    }

    /**
     * Post Foodics payments to Daftra only when the Daftra invoice has no
     * payments recorded yet. If any payments already exist on Daftra, the
     * sync is considered payment-complete (per spec 017: no Foodics-side
     * correlation, presence of any payments is treated as done).
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function syncPaymentsIfMissing(array $payments, int $daftraInvoiceId): void
    {
        $existing = $this->invoiceService->listInvoicePayments($daftraInvoiceId);

        if ($existing !== []) {
            return;
        }

        foreach ($payments as $payment) {
            $foodicsPaymentMethodId = (string) ($payment['payment_method']['id'] ?? '');
            $daftraPaymentMethodId = $this->paymentMethodMap[$foodicsPaymentMethodId] ?? null;

            $this->invoiceService->createPayment([
                'InvoicePayment' => [
                    'invoice_id' => $daftraInvoiceId,
                    'payment_method' => $daftraPaymentMethodId,
                    'amount' => $payment['amount'],
                    'date' => $payment['added_at'],
                ],
            ]);
        }
    }

    /**
     * @throws InvoiceAlreadyExistsException
     */
    protected function skipIfAlreadySynced(string $foodicsId, string $foodicsReference): void
    {
        $userId = Context::get('user')?->id;

        $blocking = Invoice::query()
            ->where('user_id', $userId)
            ->whereIn('status', [InvoiceSyncStatus::Pending, InvoiceSyncStatus::Synced])
            ->where(function ($query) use ($foodicsId, $foodicsReference) {
                $query->where('foodics_id', $foodicsId)
                    ->orWhere('foodics_reference', $foodicsReference);
            })
            ->exists();

        throw_if($blocking, new InvoiceAlreadyExistsException('Order already synced or in progress locally'));
    }

    /**
     * Insert or revive the single local row that tracks this Foodics order.
     *
     * The duplicate guard has already rejected `pending`/`synced` rows, so any
     * row found here is `failed` — we flip it back to `pending` (keeping its
     * `daftra_id` if already known) rather than creating a second row, per
     * the "exactly one local row per Foodics order" rule.
     */
    protected function createPendingInvoice(array $order): Invoice
    {
        $userId = Context::get('user')?->id;

        $invoice = Invoice::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $order['id'])
            ->first();

        if ($invoice !== null) {
            $invoice->fill([
                'foodics_reference' => $order['reference'],
                'status' => InvoiceSyncStatus::Pending,
                'foodics_metadata' => [
                    'total_price' => (float) ($order['total_price'] ?? 0),
                ],
            ])->save();

            return $invoice;
        }

        return Invoice::query()->create([
            'user_id' => $userId,
            'foodics_id' => $order['id'],
            'foodics_reference' => $order['reference'],
            'daftra_id' => null,
            'status' => InvoiceSyncStatus::Pending,
            'foodics_metadata' => [
                'total_price' => (float) ($order['total_price'] ?? 0),
            ],
        ]);
    }

    protected function resolveDefaultClientId(): ?int
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            return null;
        }

        $default = $user->setting(SettingKey::DaftraDefaultClientId);

        return $default !== null && $default !== '' ? (int) $default : null;
    }

    /**
     * @param  array<string, mixed>  $orderProduct
     */
    protected function resolveFoodicsProductId(array $orderProduct): string
    {
        $productId = data_get($orderProduct, 'product.id') ?? ($orderProduct['id'] ?? null);
        if (! is_string($productId) || trim($productId) === '') {
            throw new \RuntimeException('Order product line is missing a Foodics product id.');
        }

        return $productId;
    }
}
