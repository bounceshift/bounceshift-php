<?php

declare(strict_types=1);

namespace BounceShift;

/**
 * The sending recommendation returned by the BounceShift API.
 *
 * This is the server's actionable verdict, distinct from {@see ValidationStatus}.
 * The backing string values mirror the exact enum values used by the API.
 */
enum Recommendation: string
{
    case Deliverable = 'deliverable';
    case SendWithCaution = 'send_with_caution';
    case Risky = 'risky';
    case Undeliverable = 'undeliverable';
    case Unknown = 'unknown';

    /**
     * Whether an address with this recommendation is safe to send to.
     *
     * Only {@see self::Deliverable} and {@see self::SendWithCaution} are sendable.
     */
    public function isSendable(): bool
    {
        return $this === self::Deliverable || $this === self::SendWithCaution;
    }
}
