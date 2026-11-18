<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Exception;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\AccountErasureRefused;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use Throwable;

final class AccountExceptionsTest extends TestCase
{
    public function testMandateRequiredNamesTheOperationOnly(): void
    {
        $refusal = MandateRequired::forOperation(Operation::Estimate);

        static::assertSame('mandate.required', $refusal->violationCode());
        static::assertSame(['operation' => '040'], $refusal->params());
        static::assertSame('No active local mandate is known for the SIRET: method 040 is refused locally (ADR 0011).', $refusal->getMessage());
        static::assertNotInstanceOf(Throwable::class, $refusal->getPrevious());
    }

    public function testMandateRecordingFailedCarriesTheLastCodeAndNoPrevious(): void
    {
        $failure = MandateRecordingFailed::after(Operation::NotifyMandate, 3, 'mandate.duplicate_active');

        static::assertSame('mandate.recording_failed', $failure->violationCode());
        static::assertSame(['operation' => '030', 'attempts' => 3, 'last_code' => 'mandate.duplicate_active'], $failure->params());
        static::assertSame(
            'URSSAF accepted method 030 but the local state could not be recorded after 3 attempt(s): record it without any call (ImportMandate / RecordMandateCancellation, ADR 0009).',
            $failure->getMessage(),
        );
        static::assertNotInstanceOf(Throwable::class, $failure->getPrevious());
    }

    public function testMandateRecordingFailedOfACancellationNamesMethod031(): void
    {
        $failure = MandateRecordingFailed::after(Operation::CancelMandate, 1, 'RuntimeException');

        static::assertSame(['operation' => '031', 'attempts' => 1, 'last_code' => 'RuntimeException'], $failure->params());
        static::assertStringContainsString('method 031', $failure->getMessage());
        static::assertStringContainsString('after 1 attempt(s)', $failure->getMessage());
    }

    public function testErasureRefusedByAnActiveMandate(): void
    {
        $refusal = AccountErasureRefused::activeMandate(AccountId::fromString('account-1'), 1);

        static::assertSame('ae_account.forget_active_mandate', $refusal->violationCode());
        static::assertSame(['account_id' => 'account-1', 'count' => 1], $refusal->params());
        static::assertSame('The auto-entrepreneur still has an active mandate: cancel it first (method 031) or allow its erasure explicitly.', $refusal->getMessage());
    }

    public function testErasureRefusedBySubmissionsInFlight(): void
    {
        $refusal = AccountErasureRefused::submissionInFlight(AccountId::fromString('account-1'), 3);

        static::assertSame('ae_account.forget_submission_in_flight', $refusal->violationCode());
        static::assertSame(['account_id' => 'account-1', 'count' => 3], $refusal->params());
        static::assertSame(
            '3 declaration(s) or payment(s) of the auto-entrepreneur are being submitted: wait for their outcome, then erase the auto-entrepreneur.',
            $refusal->getMessage(),
        );
    }

    public function testErasureRefusedByUnknownOutcomes(): void
    {
        $refusal = AccountErasureRefused::unknownOutcomes(AccountId::fromString('account-1'), 2);

        static::assertSame('ae_account.forget_unknown_outcome', $refusal->violationCode());
        static::assertSame(['account_id' => 'account-1', 'count' => 2], $refusal->params());
        static::assertSame(
            '2 declaration(s) or payment(s) of the auto-entrepreneur have an unknown outcome: resolve them first or allow their erasure explicitly.',
            $refusal->getMessage(),
        );
    }

    public function testWithoutATrackedAccountThereIsNoAccountIdParameter(): void
    {
        static::assertSame(['count' => 1], AccountErasureRefused::activeMandate(null, 1)->params());
        static::assertSame(['count' => 2], AccountErasureRefused::submissionInFlight(null, 2)->params());
        static::assertSame(['count' => 4], AccountErasureRefused::unknownOutcomes(null, 4)->params());
    }

    public function testARetryableFailureKeepsTheOriginalFailureAsPrevious(): void
    {
        $original = UrssafUnavailable::withStatus(Operation::Declare, 503, 30, ApiErrors::none());

        $retryable = RetryableUrssafFailure::causedBy($original);

        static::assertSame($original, $retryable->getPrevious());
        static::assertSame('URSSAF is temporarily unavailable: the submission must be retried later.', $retryable->getMessage());
        static::assertSame(0, $retryable->getCode());
        $limited = RateLimitExceeded::forOperation(Operation::Pay, 7);
        static::assertSame($limited, RetryableUrssafFailure::causedBy($limited)->getPrevious());
    }
}
