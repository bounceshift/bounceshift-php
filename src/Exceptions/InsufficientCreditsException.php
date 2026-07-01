<?php

declare(strict_types=1);

namespace BounceShift\Exceptions;

/**
 * Raised when the organization has insufficient credits (HTTP 402).
 */
final class InsufficientCreditsException extends ApiException {}
