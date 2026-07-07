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
     * @param  ?string  $subStatus  Granular reason for the verdict, or null when not set.
     * @param  ?Recommendation  $recommendation  Actionable send recommendation, or null when
     *                                            absent or an unrecognized value (see {@see self::$recommendationValue}).
     * @param  ?string  $recommendationValue  The raw recommendation string exactly as sent by the
     *                                         API, preserved even when the enum does not recognize it.
     * @param  ?int  $qualityScore  Quality score 0-100, distinct from {@see self::$confidence}; null when absent.
     * @param  ?string  $explanation  Plain-English sentence describing the verdict, or null when absent.
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
        public ?string $subStatus = null,
        public ?Recommendation $recommendation = null,
        public ?string $recommendationValue = null,
        public ?int $qualityScore = null,
        public ?string $explanation = null,
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
     *     result?: array<string, mixed>,
     *     sub_status?: string|null,
     *     recommendation?: string|null,
     *     quality_score?: int|null,
     *     explanation?: string|null
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

        // Preserve the raw recommendation string even when the enum does not recognize it,
        // so an unfamiliar server value never throws and stays inspectable by callers.
        $recommendationValue = isset($data['recommendation']) && $data['recommendation'] !== null
            ? (string) $data['recommendation']
            : null;

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
            subStatus: isset($data['sub_status']) && $data['sub_status'] !== null ? (string) $data['sub_status'] : null,
            recommendation: $recommendationValue !== null ? Recommendation::tryFrom($recommendationValue) : null,
            recommendationValue: $recommendationValue,
            qualityScore: isset($data['quality_score']) && $data['quality_score'] !== null ? (int) $data['quality_score'] : null,
            explanation: isset($data['explanation']) && $data['explanation'] !== null ? (string) $data['explanation'] : null,
        );
    }

    /**
     * Whether this address is considered safe to send to.
     */
    public function isSafeToSend(): bool
    {
        return $this->status->isSafeToSend();
    }

    /**
     * Whether the API's send recommendation is sendable.
     *
     * Surfaces the server's actionable verdict: true only when the recommendation is
     * {@see Recommendation::Deliverable} or {@see Recommendation::SendWithCaution}. An
     * absent, null, or unrecognized recommendation is treated as not sendable.
     */
    public function isSendable(): bool
    {
        return $this->recommendation?->isSendable() ?? false;
    }
}
