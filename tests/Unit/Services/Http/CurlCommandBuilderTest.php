<?php

use App\Services\Http\CurlCommandBuilder;
use GuzzleHttp\Psr7\Request;

it('builds a minimal GET request with no body', function () {
    $request = new Request('GET', 'https://api.example.test/orders');

    $curl = CurlCommandBuilder::build($request);

    expect($curl)->toBe("curl -X GET 'https://api.example.test/orders' \\\n  -H 'Host: api.example.test'");
});

it('builds a POST with JSON body and multiple headers', function () {
    $request = new Request(
        'POST',
        'https://api.example.test/orders',
        [
            'Authorization' => 'Bearer abc',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        '{"id":1}'
    );

    $curl = CurlCommandBuilder::build($request);

    expect($curl)->toBe(
        "curl -X POST 'https://api.example.test/orders' \\\n".
        "  -H 'Host: api.example.test' \\\n".
        "  -H 'Authorization: Bearer abc' \\\n".
        "  -H 'Accept: application/json' \\\n".
        "  -H 'Content-Type: application/json' \\\n".
        "  --data-raw '{\"id\":1}'"
    );
});

it('escapes single quotes in URL, header values, and body', function () {
    $request = new Request(
        'POST',
        "https://api.example.test/search?q=it's",
        ['X-Note' => "it's fine"],
        "{\"msg\":\"it's ok\"}"
    );

    $curl = CurlCommandBuilder::build($request);

    expect($curl)
        ->toContain("'https://api.example.test/search?q=it'\\''s'")
        ->toContain("-H 'X-Note: it'\\''s fine'")
        ->toContain("--data-raw '{\"msg\":\"it'\\''s ok\"}'");
});

it('flattens multi-value headers into multiple -H lines', function () {
    $request = (new Request('GET', 'https://api.example.test/'))
        ->withHeader('X-Multi', ['one', 'two']);

    $curl = CurlCommandBuilder::build($request);

    expect($curl)
        ->toContain("-H 'X-Multi: one'")
        ->toContain("-H 'X-Multi: two'");
});

it('uppercases the HTTP method', function () {
    $request = new Request('delete', 'https://api.example.test/orders/1');

    expect(CurlCommandBuilder::build($request))->toStartWith("curl -X DELETE 'https://api.example.test/orders/1'");
});
