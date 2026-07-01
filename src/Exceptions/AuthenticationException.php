<?php

declare(strict_types=1);

namespace BounceShift\Exceptions;

/**
 * Raised when the request is not authenticated (HTTP 401).
 */
final class AuthenticationException extends ApiException {}
