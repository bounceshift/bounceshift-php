<?php

declare(strict_types=1);

namespace BounceShift;

/**
 * The set of validation statuses returned by the BounceShift API.
 *
 * The backing string values mirror the exact enum values used by the API.
 */
enum ValidationStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Risky = 'risky';
    case CatchAll = 'catch_all';
    case Unknown = 'unknown';
    case Disposable = 'disposable';
    case SpamTrap = 'spamtrap';
    case Abuse = 'abuse';
    case DoNotMail = 'do_not_mail';

    /**
     * Whether an address with this status is considered safe to send to.
     *
     * Only {@see self::Valid} and {@see self::CatchAll} are safe to send.
     */
    public function isSafeToSend(): bool
    {
        return $this === self::Valid || $this === self::CatchAll;
    }
}
