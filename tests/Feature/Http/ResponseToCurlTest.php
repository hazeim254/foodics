<?php

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;

it('exports a sent request as a curl bash command', function () {
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);

    $curl = Http::withToken('abc')
        ->acceptJson()
        ->post('https://api.example.test/orders', ['id' => 1])
        ->toCurl();

    expect($curl)
        ->toContain("curl -X POST 'https://api.example.test/orders'")
        ->toContain("-H 'Authorization: Bearer abc'")
        ->toContain("-H 'Accept: application/json'")
        ->toContain('--data-raw \'{"id":1}\'')
        ->toContain(" \\\n");
});

it('returns an empty string when transferStats is missing', function () {
    $response = new Illuminate\Http\Client\Response(
        new Response(200, [], '{}')
    );

    expect($response->toCurl())->toBe('');
});
