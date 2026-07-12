<?php

declare(strict_types=1);

namespace BounceShift;

use BounceShift\Exceptions\ApiException;
use BounceShift\Exceptions\AuthenticationException;
use BounceShift\Exceptions\BounceShiftException;
use BounceShift\Exceptions\ForbiddenException;
use BounceShift\Exceptions\InsufficientCreditsException;
use BounceShift\Exceptions\RateLimitException;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * HTTP client for the BounceShift email-validation API.
 */
final class Client
{
    /**
     * The SDK version, sent in the User-Agent header.
     */
    public const VERSION = '1.1.0';

    /**
     * The default production base URL.
     */
    public const DEFAULT_BASE_URL = 'https://api.bounceshift.com/v1';

    /**
     * Upper bound (seconds) honored from a Retry-After header, so a hostile or
     * misconfigured value can never pin the calling thread indefinitely.
     */
    private const MAX_RETRY_AFTER_SECONDS = 60;

    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    private int $retries;

    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * @param  array{
     *     base_url?: string,
     *     timeout?: int,
     *     connect_timeout?: int,
     *     retries?: int,
     *     http_client?: ClientInterface,
     *     request_factory?: RequestFactoryInterface,
     *     stream_factory?: StreamFactoryInterface
     * }  $options
     *
     * @throws BounceShiftException When the base URL is not HTTPS.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $organizationId,
        array $options = [],
    ) {
        $baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');

        // The API key travels in the Authorization header on every request, so the
        // transport must be encrypted. Refuse a plaintext base URL outright.
        if (strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new BounceShiftException('The BounceShift base URL must use HTTPS.');
        }

        $this->baseUrl = $baseUrl;
        $this->timeout = (int) ($options['timeout'] ?? 10);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 5);
        $this->retries = max(0, (int) ($options['retries'] ?? 2));
        // A caller-supplied client is used verbatim; otherwise build one with the
        // configured timeouts. Relying on discovery alone left `timeout` a dead
        // option — an overwhelmed API could hang the caller's thread indefinitely.
        $this->httpClient = $options['http_client'] ?? $this->defaultHttpClient();
        $this->requestFactory = $options['request_factory'] ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $options['stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Build the default HTTP client with the configured timeouts enforced.
     *
     * Guzzle is a hard dependency, so we construct it directly rather than via
     * PSR-18 discovery: a discovered client carries no timeout, and the whole
     * point of `timeout`/`connect_timeout` is that a stalled API must not freeze
     * the caller. `http_errors` is off so error statuses are returned, not thrown
     * (this class inspects the status code itself).
     */
    private function defaultHttpClient(): ClientInterface
    {
        return new \GuzzleHttp\Client([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => false,
        ]);
    }

    /**
     * Validate a single email address.
     *
     * @throws AuthenticationException When authentication fails (401).
     * @throws InsufficientCreditsException When the organization is out of credits (402).
     * @throws ForbiddenException When the request is forbidden (403).
     * @throws RateLimitException When rate limited and retries are exhausted (429).
     * @throws ApiException For any other non-successful response.
     * @throws BounceShiftException When the request cannot be completed.
     */
    public function validate(string $email): ValidationResult
    {
        $payload = (string) json_encode(['email' => $email], JSON_THROW_ON_ERROR);

        $response = $this->send('POST', '/validate/single', $payload);

        /** @var array<string, mixed> $data */
        $data = $this->decode($response);

        return ValidationResult::fromResponse($data);
    }

    /**
     * Validate an email without ever throwing — fail open.
     *
     * Intended for hot paths (e.g. validate-on-signup) where a validation problem
     * must never block the user. On any failure — out of credits (402), an API
     * outage (5xx), a timeout, a network error, or a malformed response — this
     * returns a degraded {@see ValidationResult} (status {@see ValidationStatus::Unknown},
     * {@see ValidationResult::isDegraded()} true) instead of throwing, so the caller
     * can let the address through rather than crash. Use {@see self::validate()}
     * when you want to handle the typed exceptions yourself.
     */
    public function validateSafe(string $email): ValidationResult
    {
        try {
            return $this->validate($email);
        } catch (BounceShiftException $e) {
            return ValidationResult::degraded($email, $e->getMessage());
        }
    }

    /**
     * Send an HTTP request with retry handling for 429 and 5xx responses.
     *
     * @throws BounceShiftException
     */
    private function send(string $method, string $path, string $body): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $request = $this->requestFactory
                ->createRequest($method, $this->baseUrl.$path)
                ->withHeader('Authorization', 'Bearer '.$this->apiKey)
                ->withHeader('X-Organization-ID', $this->organizationId)
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('User-Agent', 'bounceshift-php/'.self::VERSION)
                ->withBody($this->streamFactory->createStream($body));

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                // Never surface the API key; use the transport message only.
                throw new BounceShiftException('HTTP request failed: '.$e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();

            if ($status < 400) {
                return $response;
            }

            if ($this->shouldRetry($status) && $attempt < $this->retries) {
                $this->backoff($attempt, $this->retryAfterSeconds($response));
                $attempt++;

                continue;
            }

            $this->throwForStatus($status, $response);
        }
    }

    /**
     * Whether a response status is retryable.
     */
    private function shouldRetry(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Sleep for a short backoff period before the next retry.
     */
    private function backoff(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            $micros = $retryAfter * 1_000_000;
        } else {
            // Exponential backoff: 100ms, 200ms, 400ms, ...
            $micros = (int) (100_000 * (2 ** $attempt));
        }

        usleep($micros);
    }

    /**
     * Extract a numeric Retry-After header value in seconds, if present.
     *
     * Clamped to MAX_RETRY_AFTER_SECONDS so an absurd value cannot stall the caller.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        if ($header === '' || ! is_numeric($header)) {
            return null;
        }

        return min(max((int) $header, 0), self::MAX_RETRY_AFTER_SECONDS);
    }

    /**
     * Map an error response to a typed exception and throw it.
     *
     * @throws ApiException
     */
    private function throwForStatus(int $status, ResponseInterface $response): never
    {
        $body = $this->decodeSafe($response);
        $message = $this->messageFromBody($body, $status);

        throw match ($status) {
            401 => new AuthenticationException($message, $status, $body),
            402 => new InsufficientCreditsException($message, $status, $body),
            403 => new ForbiddenException($message, $status, $body),
            429 => new RateLimitException($message, $status, $body, $this->retryAfterSeconds($response)),
            default => new ApiException($message, $status, $body),
        };
    }

    /**
     * Derive a human-readable message from the response body.
     *
     * @param  array<string, mixed>  $body
     */
    private function messageFromBody(array $body, int $status): string
    {
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }

        if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
            return $body['error'];
        }

        return 'BounceShift API request failed with HTTP status '.$status.'.';
    }

    /**
     * Decode a successful JSON response, throwing on malformed payloads.
     *
     * @return array<string, mixed>
     *
     * @throws BounceShiftException
     */
    private function decode(ResponseInterface $response): array
    {
        $contents = (string) $response->getBody();

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BounceShiftException('Failed to decode API response: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new BounceShiftException('Unexpected API response: expected a JSON object.');
        }

        return $decoded;
    }

    /**
     * Decode a response body without throwing; returns an empty array on failure.
     *
     * @return array<string, mixed>
     */
    private function decodeSafe(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
