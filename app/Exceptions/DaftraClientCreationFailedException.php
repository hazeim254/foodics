<?php

namespace App\Exceptions;

use App\Exceptions\Concerns\HasResponseBody;
use RuntimeException;
use Throwable;

class DaftraClientCreationFailedException extends RuntimeException implements LoggableException
{
    use HasResponseBody;

    public function __construct(
        string $message = 'Failed to create client in Daftra.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
