<?php

declare(strict_types=1);

namespace BounceShift\Exceptions;

use Throwable;

/**
 * Raised when the API returns a non-successful HTTP response.
 */
class ApiException extends BounceShiftException
{
    /**
     * @param  array<string, mixed>  $body  The decoded response body, when available.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
