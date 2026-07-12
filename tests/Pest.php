<?php

declare(strict_types=1);

use BounceShift\Client;
use BounceShift\Tests\Support\MockHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
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
 * A successful single-validation payload, with optional field overrides.
 *
 * @param  array<string, mixed>  $bodyOverrides
 * @return array<string, mixed>
 */
function successPayload(array $bodyOverrides = []): array
{
    return array_merge([
        'email' => 'user@example.com',
        'status' => 'valid',
        'confidence' => 95,
        'mx_found' => true,
        'smtp_valid' => true,
        'is_disposable' => false,
        'is_catch_all' => false,
        'is_role_account' => false,
        'from_cache' => false,
        'credits_used' => 1,
        'result' => ['sub_status' => 'mailbox_exists'],
    ], $bodyOverrides);
}

/**
 * Build a Client backed by the given PSR-18 test double.
 */
function makeClient(\Psr\Http\Client\ClientInterface $http): Client
{
    $factory = new HttpFactory;

    return new Client('secret-key', 'org_123', [
        'http_client' => $http,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'retries' => 2,
    ]);
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
