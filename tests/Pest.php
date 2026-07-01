<?php

declare(strict_types=1);

use BounceShift\Tests\Support\MockHttpClient;
use GuzzleHttp\Psr7\Response;

/**
 * Build a mock PSR-18 client that returns the given queued responses in order.
 *
 * @param  Response|Response[]  $responses
 */
function mockHttpClient(Response|array $responses): MockHttpClient
{
    return new MockHttpClient(is_array($responses) ? $responses : [$responses]);
}

/**
 * Build a JSON PSR-7 response.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function jsonResponse(array $data, int $status = 200, array $headers = []): Response
{
    return new Response(
        $status,
        array_merge(['Content-Type' => 'application/json'], $headers),
        (string) json_encode($data),
    );
}
