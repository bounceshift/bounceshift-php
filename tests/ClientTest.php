<?php

declare(strict_types=1);

use BounceShift\Client;
use BounceShift\Exceptions\ApiException;
use BounceShift\Exceptions\AuthenticationException;
use BounceShift\Exceptions\BounceShiftException;
use BounceShift\Exceptions\ForbiddenException;
use BounceShift\Exceptions\InsufficientCreditsException;
use BounceShift\Exceptions\RateLimitException;
use BounceShift\ValidationStatus;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * @param  array<string, mixed>|null  $bodyOverrides
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

it('maps a 200 response into a ValidationResult', function () {
    $http = mockHttpClient(jsonResponse(successPayload()));
    $client = makeClient($http);

    $result = $client->validate('user@example.com');

    expect($result->email)->toBe('user@example.com')
        ->and($result->status)->toBe(ValidationStatus::Valid)
        ->and($result->confidence)->toBe(95)
        ->and($result->mxFound)->toBeTrue()
        ->and($result->smtpValid)->toBeTrue()
        ->and($result->isDisposable)->toBeFalse()
        ->and($result->isCatchAll)->toBeFalse()
        ->and($result->isRoleAccount)->toBeFalse()
        ->and($result->fromCache)->toBeFalse()
        ->and($result->creditsUsed)->toBe(1)
        ->and($result->result)->toBe(['sub_status' => 'mailbox_exists'])
        ->and($result->isSafeToSend())->toBeTrue();
});

it('maps smtp_valid null correctly', function () {
    $http = mockHttpClient(jsonResponse(successPayload([
        'status' => 'unknown',
        'smtp_valid' => null,
    ])));
    $client = makeClient($http);

    $result = $client->validate('user@example.com');

    expect($result->smtpValid)->toBeNull()
        ->and($result->status)->toBe(ValidationStatus::Unknown)
        ->and($result->isSafeToSend())->toBeFalse();
});

it('reports catch_all as safe to send', function () {
    $http = mockHttpClient(jsonResponse(successPayload(['status' => 'catch_all'])));

    $result = makeClient($http)->validate('user@example.com');

    expect($result->status)->toBe(ValidationStatus::CatchAll)
        ->and($result->isSafeToSend())->toBeTrue();
});

it('reports invalid as not safe to send', function () {
    $http = mockHttpClient(jsonResponse(successPayload(['status' => 'invalid'])));

    $result = makeClient($http)->validate('user@example.com');

    expect($result->status)->toBe(ValidationStatus::Invalid)
        ->and($result->isSafeToSend())->toBeFalse();
});

it('sends the required headers and body', function () {
    $http = mockHttpClient(jsonResponse(successPayload()));
    $client = makeClient($http);

    $client->validate('user@example.com');

    $request = $http->lastRequest();

    expect($request)->not->toBeNull()
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://api.bounceshift.com/v1/validate/single')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-key')
        ->and($request->getHeaderLine('X-Organization-ID'))->toBe('org_123')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('User-Agent'))->toBe('bounceshift-php/'.Client::VERSION)
        ->and((string) $request->getBody())->toBe('{"email":"user@example.com"}');
});

it('throws AuthenticationException on 401', function () {
    $http = mockHttpClient(jsonResponse(['error' => 'unauthenticated', 'message' => 'Bad key'], 401));

    expect(fn () => makeClient($http)->validate('user@example.com'))
        ->toThrow(AuthenticationException::class, 'Bad key');
});

it('throws InsufficientCreditsException on 402', function () {
    $http = mockHttpClient(jsonResponse(['error' => 'insufficient_credits', 'message' => 'No credits'], 402));

    try {
        makeClient($http)->validate('user@example.com');
        $this->fail('Expected exception was not thrown.');
    } catch (InsufficientCreditsException $e) {
        expect($e->statusCode)->toBe(402)
            ->and($e->body)->toBe(['error' => 'insufficient_credits', 'message' => 'No credits'])
            ->and($e->getMessage())->toBe('No credits');
    }
});

it('throws ForbiddenException on 403', function () {
    $http = mockHttpClient(jsonResponse(['error' => 'forbidden', 'message' => 'Nope'], 403));

    expect(fn () => makeClient($http)->validate('user@example.com'))
        ->toThrow(ForbiddenException::class, 'Nope');
});

it('throws RateLimitException on 429 with retry-after when retries are exhausted', function () {
    $rateLimited = fn () => jsonResponse(['error' => 'rate_limited'], 429, ['Retry-After' => '0']);
    $http = mockHttpClient([$rateLimited(), $rateLimited(), $rateLimited()]);

    try {
        makeClient($http)->validate('user@example.com');
        $this->fail('Expected exception was not thrown.');
    } catch (RateLimitException $e) {
        expect($e->statusCode)->toBe(429)
            ->and($e->retryAfter)->toBe(0);
    }
});

it('retries a 5xx then succeeds on 200', function () {
    $http = mockHttpClient([
        jsonResponse(['error' => 'server_error'], 503),
        jsonResponse(successPayload()),
    ]);
    $client = makeClient($http);

    $result = $client->validate('user@example.com');

    expect($result->status)->toBe(ValidationStatus::Valid)
        ->and($http->requests)->toHaveCount(2);
});

it('throws a generic ApiException for other non-2xx statuses', function () {
    $http = mockHttpClient(jsonResponse(['error' => 'teapot'], 418));

    try {
        makeClient($http)->validate('user@example.com');
        $this->fail('Expected exception was not thrown.');
    } catch (ApiException $e) {
        expect($e)->not->toBeInstanceOf(AuthenticationException::class)
            ->and($e->statusCode)->toBe(418)
            ->and($e->body)->toBe(['error' => 'teapot']);
    }
});

it('never exposes the API key in exception messages', function () {
    // 500 is retryable, so queue enough responses to exhaust the default retries.
    $error = fn () => jsonResponse(['message' => 'boom'], 500);
    $http = mockHttpClient([$error(), $error(), $error()]);

    try {
        makeClient($http)->validate('user@example.com');
        $this->fail('Expected exception was not thrown.');
    } catch (ApiException $e) {
        expect($e->getMessage())->not->toContain('secret-key');
    }
});

it('refuses a non-HTTPS base URL so the API key is never sent in cleartext', function () {
    $factory = new HttpFactory;

    expect(fn () => new Client('secret-key', 'org_123', [
        'base_url' => 'http://insecure.example/v1',
        'http_client' => mockHttpClient([]),
        'request_factory' => $factory,
        'stream_factory' => $factory,
    ]))->toThrow(BounceShiftException::class);
});

it('wraps an unknown status in a BounceShiftException so callers can fail open', function () {
    $http = mockHttpClient(jsonResponse(successPayload(['status' => 'not_a_real_status'])));

    expect(fn () => makeClient($http)->validate('user@example.com'))
        ->toThrow(BounceShiftException::class);
});

it('clamps an absurd Retry-After to the maximum instead of stalling', function () {
    $factory = new HttpFactory;
    $http = mockHttpClient(jsonResponse(['error' => 'rate_limited'], 429, ['Retry-After' => '99999999']));

    $client = new Client('secret-key', 'org_123', [
        'http_client' => $http,
        'request_factory' => $factory,
        'stream_factory' => $factory,
        'retries' => 0,
    ]);

    try {
        $client->validate('user@example.com');
        $this->fail('Expected exception was not thrown.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(60);
    }
});
