<?php

use App\Exceptions\DaftraClientCreationFailedException;
use App\Exceptions\DaftraInvoiceCreationFailedException;
use App\Exceptions\DaftraPaymentCreationFailedException;
use App\Exceptions\DaftraProductCreationFailedException;
use App\Exceptions\InvoiceAlreadyExistsException;
use App\Exceptions\LoggableException;
use Illuminate\Support\Facades\Log;

it('logs DaftraClientCreationFailedException with response body', function () {
    Log::expects('error')->with('Failed to create client in Daftra.', [
        'exception' => DaftraClientCreationFailedException::class,
        'response_body' => '{"error": "client exists"}',
    ]);

    $exception = new DaftraClientCreationFailedException(
        responseBody: '{"error": "client exists"}',
    );
    $exception->report();
});

it('logs DaftraInvoiceCreationFailedException with response body', function () {
    Log::expects('error')->with('Failed to create invoice in Daftra.', [
        'exception' => DaftraInvoiceCreationFailedException::class,
        'response_body' => '{"error": "invoice exists"}',
    ]);

    $exception = new DaftraInvoiceCreationFailedException(
        responseBody: '{"error": "invoice exists"}',
    );
    $exception->report();
});

it('logs DaftraPaymentCreationFailedException with response body', function () {
    Log::expects('error')->with('Failed to create invoice payment in Daftra.', [
        'exception' => DaftraPaymentCreationFailedException::class,
        'response_body' => '{"error": "payment failed"}',
    ]);

    $exception = new DaftraPaymentCreationFailedException(
        responseBody: '{"error": "payment failed"}',
    );
    $exception->report();
});

it('logs DaftraProductCreationFailedException with response body', function () {
    Log::expects('error')->with('Failed to create product in Daftra.', [
        'exception' => DaftraProductCreationFailedException::class,
        'response_body' => '{"error": "product exists"}',
    ]);

    $exception = new DaftraProductCreationFailedException(
        responseBody: '{"error": "product exists"}',
    );
    $exception->report();
});

it('logs Daftra exceptions with null response body', function () {
    Log::expects('error')->with('Failed to create client in Daftra.', [
        'exception' => DaftraClientCreationFailedException::class,
        'response_body' => null,
    ]);

    $exception = new DaftraClientCreationFailedException;
    $exception->report();
});

it('logs InvoiceAlreadyExistsException as warning without response body', function () {
    Log::expects('warning')->with('Invoice already exists.', [
        'exception' => InvoiceAlreadyExistsException::class,
    ]);

    $exception = new InvoiceAlreadyExistsException;
    $exception->report();
});

it('all custom exceptions implement LoggableException', function () {
    $exceptions = [
        DaftraClientCreationFailedException::class,
        DaftraInvoiceCreationFailedException::class,
        DaftraPaymentCreationFailedException::class,
        DaftraProductCreationFailedException::class,
        InvoiceAlreadyExistsException::class,
    ];

    foreach ($exceptions as $exceptionClass) {
        expect(is_subclass_of($exceptionClass, LoggableException::class))->toBeTrue(
            "$exceptionClass should implement LoggableException",
        );
    }
});
