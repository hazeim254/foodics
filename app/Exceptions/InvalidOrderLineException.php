<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InvalidOrderLineException extends RuntimeException implements LoggableException
{
    protected ?string $orderId = null;

    protected ?string $lineIdentifier = null;

    public function __construct(
        string $message = 'Order line is missing a required Foodics id.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function setLineIdentifier(?string $lineIdentifier): self
    {
        $this->lineIdentifier = $lineIdentifier;

        return $this;
    }

    public function report(): void
    {
        Log::warning($this->getMessage(), array_filter([
            'exception' => static::class,
            'order_id' => $this->orderId,
            'line_identifier' => $this->lineIdentifier,
        ]));
    }
}
