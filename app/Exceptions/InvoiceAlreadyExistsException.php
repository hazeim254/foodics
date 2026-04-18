<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InvoiceAlreadyExistsException extends RuntimeException implements LoggableException
{
    public function __construct(
        string $message = 'Invoice already exists.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function report(): void
    {
        Log::warning($this->getMessage(), [
            'exception' => static::class,
        ]);
    }
}
