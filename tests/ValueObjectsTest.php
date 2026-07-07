<?php

declare(strict_types=1);

use BounceShift\Recommendation;
use BounceShift\ValidationResult;
use BounceShift\ValidationStatus;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function baseResponse(array $overrides = []): array
{
    return array_merge([
        'email' => 'a@b.com',
        'status' => 'valid',
        'confidence' => 95,
        'mx_found' => true,
        'smtp_valid' => true,
        'is_disposable' => false,
        'is_catch_all' => false,
        'is_role_account' => false,
        'from_cache' => false,
        'credits_used' => 1,
    ], $overrides);
}

it('exposes exact backing values for every status', function () {
    expect(ValidationStatus::Valid->value)->toBe('valid')
        ->and(ValidationStatus::Invalid->value)->toBe('invalid')
        ->and(ValidationStatus::Risky->value)->toBe('risky')
        ->and(ValidationStatus::CatchAll->value)->toBe('catch_all')
        ->and(ValidationStatus::Unknown->value)->toBe('unknown')
        ->and(ValidationStatus::Disposable->value)->toBe('disposable')
        ->and(ValidationStatus::SpamTrap->value)->toBe('spamtrap')
        ->and(ValidationStatus::Abuse->value)->toBe('abuse')
        ->and(ValidationStatus::DoNotMail->value)->toBe('do_not_mail');
});

it('marks only valid and catch_all as safe to send', function () {
    expect(ValidationStatus::Valid->isSafeToSend())->toBeTrue()
        ->and(ValidationStatus::CatchAll->isSafeToSend())->toBeTrue()
        ->and(ValidationStatus::Invalid->isSafeToSend())->toBeFalse()
        ->and(ValidationStatus::Risky->isSafeToSend())->toBeFalse()
        ->and(ValidationStatus::Unknown->isSafeToSend())->toBeFalse()
        ->and(ValidationStatus::Disposable->isSafeToSend())->toBeFalse()
        ->and(ValidationStatus::SpamTrap->isSafeToSend())->toBeFalse()
        ->and(ValidationStatus::Abuse->isSafeToSend())->toBeFalse()
        ->and(ValidationStatus::DoNotMail->isSafeToSend())->toBeFalse();
});

it('builds a ValidationResult from a response and defaults result to an array', function () {
    $result = ValidationResult::fromResponse([
        'email' => 'a@b.com',
        'status' => 'risky',
        'confidence' => 40,
        'mx_found' => true,
        'smtp_valid' => null,
        'is_disposable' => false,
        'is_catch_all' => true,
        'is_role_account' => true,
        'from_cache' => true,
        'credits_used' => 0,
    ]);

    expect($result)->toBeInstanceOf(ValidationResult::class)
        ->and($result->status)->toBe(ValidationStatus::Risky)
        ->and($result->smtpValid)->toBeNull()
        ->and($result->isCatchAll)->toBeTrue()
        ->and($result->isRoleAccount)->toBeTrue()
        ->and($result->fromCache)->toBeTrue()
        ->and($result->creditsUsed)->toBe(0)
        ->and($result->result)->toBe([])
        ->and($result->isSafeToSend())->toBeFalse();
});

it('exposes exact backing values for every recommendation', function () {
    expect(Recommendation::Deliverable->value)->toBe('deliverable')
        ->and(Recommendation::SendWithCaution->value)->toBe('send_with_caution')
        ->and(Recommendation::Risky->value)->toBe('risky')
        ->and(Recommendation::Undeliverable->value)->toBe('undeliverable')
        ->and(Recommendation::Unknown->value)->toBe('unknown');
});

it('marks only deliverable and send_with_caution as sendable', function () {
    expect(Recommendation::Deliverable->isSendable())->toBeTrue()
        ->and(Recommendation::SendWithCaution->isSendable())->toBeTrue()
        ->and(Recommendation::Risky->isSendable())->toBeFalse()
        ->and(Recommendation::Undeliverable->isSendable())->toBeFalse()
        ->and(Recommendation::Unknown->isSendable())->toBeFalse();
});

it('parses the additional response fields', function () {
    $result = ValidationResult::fromResponse(baseResponse([
        'sub_status' => 'smtp_verified',
        'recommendation' => 'deliverable',
        'quality_score' => 88,
        'explanation' => 'The mailbox exists and accepted mail during the SMTP probe.',
    ]));

    expect($result->subStatus)->toBe('smtp_verified')
        ->and($result->recommendation)->toBe(Recommendation::Deliverable)
        ->and($result->recommendationValue)->toBe('deliverable')
        ->and($result->qualityScore)->toBe(88)
        ->and($result->explanation)->toBe('The mailbox exists and accepted mail during the SMTP probe.')
        ->and($result->isSendable())->toBeTrue();
});

it('models quality_score separately from confidence', function () {
    $result = ValidationResult::fromResponse(baseResponse([
        'confidence' => 60,
        'quality_score' => 90,
    ]));

    expect($result->confidence)->toBe(60)
        ->and($result->qualityScore)->toBe(90);
});

it('reports a caution recommendation as sendable', function () {
    $result = ValidationResult::fromResponse(baseResponse([
        'status' => 'catch_all',
        'recommendation' => 'send_with_caution',
        'sub_status' => 'unverifiable_catch_all',
    ]));

    expect($result->recommendation)->toBe(Recommendation::SendWithCaution)
        ->and($result->isSendable())->toBeTrue();
});

it('reports a risky recommendation as not sendable', function () {
    $result = ValidationResult::fromResponse(baseResponse([
        'status' => 'risky',
        'recommendation' => 'risky',
    ]));

    expect($result->recommendation)->toBe(Recommendation::Risky)
        ->and($result->isSendable())->toBeFalse();
});

it('does not throw when recommendation is absent and is not sendable', function () {
    $result = ValidationResult::fromResponse(baseResponse());

    expect($result->recommendation)->toBeNull()
        ->and($result->recommendationValue)->toBeNull()
        ->and($result->subStatus)->toBeNull()
        ->and($result->qualityScore)->toBeNull()
        ->and($result->explanation)->toBeNull()
        ->and($result->isSendable())->toBeFalse();
});

it('does not throw when recommendation is explicitly null and is not sendable', function () {
    $result = ValidationResult::fromResponse(baseResponse([
        'recommendation' => null,
        'sub_status' => null,
        'quality_score' => null,
        'explanation' => null,
    ]));

    expect($result->recommendation)->toBeNull()
        ->and($result->recommendationValue)->toBeNull()
        ->and($result->isSendable())->toBeFalse();
});

it('does not throw on an unknown recommendation string and treats it as not sendable', function () {
    $result = ValidationResult::fromResponse(baseResponse([
        'recommendation' => 'maybe_someday',
    ]));

    expect($result->recommendation)->toBeNull()
        ->and($result->recommendationValue)->toBe('maybe_someday')
        ->and($result->isSendable())->toBeFalse();
});
