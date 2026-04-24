<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OriginalInvoiceNotSyncedException extends RuntimeException implements LoggableException
{
    protected ?string $originalFoodicsId = null;

    protected ?string $returnFoodicsId = null;

    public function __construct(
        string $message = 'Original invoice not yet synced; cannot emit credit note.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function setOriginalFoodicsId(?string $originalFoodicsId): self
    {
        $this->originalFoodicsId = $originalFoodicsId;

        return $this;
    }

    public function setReturnFoodicsId(?string $returnFoodicsId): self
    {
        $this->returnFoodicsId = $returnFoodicsId;

        return $this;
    }

    public function report(): void
    {
        Log::warning($this->getMessage(), array_filter([
            'exception' => static::class,
            'original_foodics_id' => $this->originalFoodicsId,
            'return_foodics_id' => $this->returnFoodicsId,
        ]));
    }
}
