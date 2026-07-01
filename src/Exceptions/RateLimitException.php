<?php

declare(strict_types=1);

namespace BounceShift\Exceptions;

use Throwable;

/**
 * Raised when the API rate limit is exceeded (HTTP 429).
 */
final class RateLimitException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body  The decoded response body, when available.
     * @param  int|null  $retryAfter  The number of seconds to wait before retrying, if provided.
     */
    public function __construct(
        string $message,
        int $statusCode,
        array $body = [],
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $body, $previous);
    }
}
