<?php

namespace App\Services\Http;

use Psr\Http\Message\RequestInterface;

class CurlCommandBuilder
{
    public static function build(RequestInterface $request): string
    {
        $method = strtoupper($request->getMethod());
        $url = (string) $request->getUri();

        $lines = ["curl -X {$method} '".self::escape($url)."'"];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "  -H '".self::escape($name.': '.$value)."'";
            }
        }

        $body = (string) $request->getBody();

        if ($body !== '') {
            $lines[] = "  --data-raw '".self::escape($body)."'";
        }

        return implode(" \\\n", $lines);
    }

    private static function escape(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }
}
