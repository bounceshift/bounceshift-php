<?php

declare(strict_types=1);

namespace BounceShift;

use BounceShift\Exceptions\BounceShiftException;

/**
 * Immutable value object representing the outcome of a single-email validation.
 */
final readonly class ValidationResult
{
    /**
     * @param  array<string, mixed>  $result  Freeform sub-status detail from the API.
     */
    public function __construct(
        public string $email,
        public ValidationStatus $status,
        public int $confidence,
        public bool $mxFound,
        public ?bool $smtpValid,
        public bool $isDisposable,
        public bool $isCatchAll,
        public bool $isRoleAccount,
        public bool $fromCache,
        public int $creditsUsed,
        public array $result,
    ) {}

    /**
     * Build a result from a decoded API response payload.
     *
     * @param  array{
     *     email: string,
     *     status: string,
     *     confidence: int,
     *     mx_found: bool,
     *     smtp_valid: bool|null,
     *     is_disposable: bool,
     *     is_catch_all: bool,
     *     is_role_account: bool,
     *     from_cache: bool,
     *     credits_used: int,
     *     result?: array<string, mixed>
     * }  $data
     *
     * @throws BounceShiftException When the payload carries an unknown status, so
     *                              every failure path stays a BounceShiftException.
     */
    public static function fromResponse(array $data): self
    {
        $status = ValidationStatus::tryFrom((string) ($data['status'] ?? ''));

        if ($status === null) {
            throw new BounceShiftException('Unexpected API response: unknown validation status.');
        }

        return new self(
            email: (string) $data['email'],
            status: $status,
            confidence: (int) $data['confidence'],
            mxFound: (bool) $data['mx_found'],
            smtpValid: $data['smtp_valid'] === null ? null : (bool) $data['smtp_valid'],
            isDisposable: (bool) $data['is_disposable'],
            isCatchAll: (bool) $data['is_catch_all'],
            isRoleAccount: (bool) $data['is_role_account'],
            fromCache: (bool) $data['from_cache'],
            creditsUsed: (int) $data['credits_used'],
            result: isset($data['result']) && is_array($data['result']) ? $data['result'] : [],
        );
    }

    /**
     * Whether this address is considered safe to send to.
     */
    public function isSafeToSend(): bool
    {
        return $this->status->isSafeToSend();
    }
}
