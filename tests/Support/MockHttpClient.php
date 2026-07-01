<?php

declare(strict_types=1);

namespace BounceShift\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A minimal in-memory PSR-18 client that replays a queue of responses and
 * records the requests it received. No network access is performed.
 */
final class MockHttpClient implements ClientInterface
{
    /**
     * @var list<ResponseInterface>
     */
    private array $responses;

    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * @param  list<ResponseInterface>  $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException('MockHttpClient has no more queued responses.');
        }

        return $response;
    }

    /**
     * The most recent request received, or null when none.
     */
    public function lastRequest(): ?RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }
}
