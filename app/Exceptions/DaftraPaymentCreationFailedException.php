<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class DaftraPaymentCreationFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Failed to create invoice payment in Daftra.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
