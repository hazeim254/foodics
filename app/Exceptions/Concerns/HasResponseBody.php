<?php

namespace App\Exceptions\Concerns;

use Illuminate\Support\Facades\Log;

trait HasResponseBody
{
    public function report(): void
    {
        Log::error($this->getMessage(), [
            'exception' => static::class,
            'response_body' => $this->responseBody,
        ]);
    }
}
