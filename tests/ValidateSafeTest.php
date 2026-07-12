<?php

declare(strict_types=1);

use BounceShift\Client;
use BounceShift\Exceptions\InsufficientCreditsException;
use BounceShift\ValidationResult;
use BounceShift\ValidationStatus;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that always fails at the transport level (timeout / DNS /
 * refused), so we can exercise the network-failure fail-open path.
 */
function throwingHttpClient(): ClientInterface
{
    return new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('Connection timed out') extends RuntimeException implements ClientExceptionInterface {};
        }
    };
}

it('returns the real verdict when the API responds', function () {
    $client = makeClient(mockHttpClient(jsonResponse(successPayload())));

    $result = $client->validateSafe('user@example.com');

    expect($result->status)->toBe(ValidationStatus::Valid)
        ->and($result->isDegraded())->toBeFalse()
        ->and($result->isSafeToSend())->toBeTrue();
});

it('fails open with a degraded result when the team is out of credits', function () {
    $client = makeClient(mockHttpClient(jsonResponse(
        ['error' => 'Insufficient credits', 'message' => 'Insufficient credits. Required: 1, Available: 0'],
        402,
    )));

    $result = $client->validateSafe('user@example.com');

    expect($result->status)->toBe(ValidationStatus::Unknown)
        ->and($result->isDegraded())->toBeTrue()
        ->and($result->subStatus)->toBe('validation_unavailable')
        ->and($result->creditsUsed)->toBe(0)
        ->and($result->email)->toBe('user@example.com');
});

it('fails open when the API is down (5xx after retries)', function () {
    $client = makeClient(mockHttpClient([
        jsonResponse(['error' => 'Server Error'], 500),
        jsonResponse(['error' => 'Server Error'], 500),
        jsonResponse(['error' => 'Server Error'], 500),
    ]));

    $result = $client->validateSafe('user@example.com');

    expect($result->isDegraded())->toBeTrue()
        ->and($result->status)->toBe(ValidationStatus::Unknown);
});

it('fails open on a transport failure (timeout / network error)', function () {
    $client = makeClient(throwingHttpClient());

    $result = $client->validateSafe('user@example.com');

    expect($result->isDegraded())->toBeTrue()
        ->and($result->status)->toBe(ValidationStatus::Unknown);
});

it('fails open on a malformed response body', function () {
    $client = makeClient(mockHttpClient(new Response(200, ['Content-Type' => 'application/json'], 'not-json')));

    $result = $client->validateSafe('user@example.com');

    expect($result->isDegraded())->toBeTrue();
});

it('leaves validate() throwing so the typed-exception contract is unchanged', function () {
    $client = makeClient(mockHttpClient(jsonResponse(['error' => 'Insufficient credits'], 402)));

    $client->validate('user@example.com');
})->throws(InsufficientCreditsException::class);

it('degraded() builds an inspectable placeholder distinct from a real verdict', function () {
    $degraded = ValidationResult::degraded('x@y.com', 'boom');
    $real = ValidationResult::fromResponse(successPayload());

    expect($degraded->isDegraded())->toBeTrue()
        ->and($degraded->result['reason'])->toBe('boom')
        ->and($real->isDegraded())->toBeFalse();
});

it('enforces the configured timeouts on the default HTTP client (fixes the dead timeout)', function () {
    $client = new Client('secret-key', 'org_123', ['timeout' => 3, 'connect_timeout' => 2]);

    $http = (new ReflectionProperty($client, 'httpClient'))->getValue($client);
    expect($http)->toBeInstanceOf(GuzzleHttp\Client::class);

    $config = (new ReflectionProperty($http, 'config'))->getValue($http);
    expect($config['timeout'])->toBe(3)
        ->and($config['connect_timeout'])->toBe(2);
});
