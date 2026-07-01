<?php

declare(strict_types=1);

use BounceShift\ValidationResult;
use BounceShift\ValidationStatus;

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
