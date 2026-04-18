<?php

namespace App\Exceptions;

interface LoggableException
{
    public function report(): void;
}
