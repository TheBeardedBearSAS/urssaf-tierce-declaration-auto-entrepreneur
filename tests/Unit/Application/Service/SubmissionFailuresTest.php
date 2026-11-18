<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\SubmissionFailureKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\SubmissionFailures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use Throwable;

final class SubmissionFailuresTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SubmissionFailureKind, SubmissionFailureKind}> the case, the kind when a resend
     *                                                                                       is allowed (050), the kind when it is not (060)
     */
    public static function failures(): iterable
    {
        yield 'rejected (400)' => ['rejected', SubmissionFailureKind::Rejected, SubmissionFailureKind::Rejected];
        yield 'credentials refused (401)' => ['unauthorised', SubmissionFailureKind::InvalidCredentials, SubmissionFailureKind::InvalidCredentials];
        yield 'outcome unknown' => ['outcome unknown', SubmissionFailureKind::OutcomeUnknown, SubmissionFailureKind::OutcomeUnknown];
        yield 'server error that may have reached URSSAF' => ['server error', SubmissionFailureKind::Resendable, SubmissionFailureKind::OutcomeUnknown];
        yield 'transport error that may have reached URSSAF' => ['transport error', SubmissionFailureKind::Resendable, SubmissionFailureKind::OutcomeUnknown];
        yield 'quota exceeded (429)' => ['quota', SubmissionFailureKind::NotSent, SubmissionFailureKind::NotSent];
        yield 'token unavailable' => ['token', SubmissionFailureKind::NotSent, SubmissionFailureKind::NotSent];
        yield 'local rate limit' => ['rate limit', SubmissionFailureKind::NotSent, SubmissionFailureKind::NotSent];
        yield 'credentials not found (nothing sent)' => ['credentials not found', SubmissionFailureKind::LocalRefusal, SubmissionFailureKind::LocalRefusal];
        yield 'another record not found' => ['mandate not found', SubmissionFailureKind::Unexpected, SubmissionFailureKind::Unexpected];
        yield 'another domain exception' => ['invalid value', SubmissionFailureKind::Unexpected, SubmissionFailureKind::Unexpected];
        yield 'a foreign throwable' => ['foreign', SubmissionFailureKind::Unexpected, SubmissionFailureKind::Unexpected];
    }

    #[DataProvider('failures')]
    public function testEveryFailureHasItsSettlementKind(string $case, SubmissionFailureKind $whenResendable, SubmissionFailureKind $whenNever): void
    {
        $failure = self::failure($case);

        static::assertSame($whenResendable, SubmissionFailures::classify($failure, true));
        static::assertSame($whenNever, SubmissionFailures::classify($failure, false));
    }

    public function testOnlyARecordNotFoundWithTheCredentialsCodeIsALocalRefusal(): void
    {
        $violation = InvariantViolation::because(SubmissionFailures::CREDENTIALS_NOT_FOUND, 'Not a RecordNotFound.');

        static::assertSame(SubmissionFailureKind::Unexpected, SubmissionFailures::classify($violation, true));
        static::assertSame(SubmissionFailureKind::Unexpected, SubmissionFailures::classify($violation, false));
    }

    public function testTheCredentialsCodeIsTheOneOfTheGatewayPorts(): void
    {
        static::assertSame(SubmissionFailures::CREDENTIALS_NOT_FOUND, RecordNotFound::withIdentifier('credentials', 'x')->violationCode());
    }

    private static function failure(string $case): Throwable
    {
        $reached = UrssafUnavailable::withStatus(Operation::Declare, 503, 30, ApiErrors::none());

        return match ($case) {
            'rejected' => UrssafRequestRejected::withErrors(Operation::Declare, 400, ApiErrors::none(), RejectionKind::PeriodNotOpen),
            'unauthorised' => UrssafAuthenticationFailed::withStatus(401),
            'outcome unknown' => OutcomeUnknown::fromUnavailable($reached),
            'server error' => $reached,
            'transport error' => UrssafUnavailable::dueToTransportError(Operation::Declare, new RuntimeException('reset')),
            'quota' => UrssafUnavailable::withStatus(Operation::Declare, 429, 5, ApiErrors::none()),
            'token' => UrssafUnavailable::duringAuthentication(Operation::Declare, 503, null, null),
            'rate limit' => RateLimitExceeded::forOperation(Operation::Declare, 5),
            'credentials not found' => RecordNotFound::withIdentifier('credentials', 'tenant-1'),
            'mandate not found' => RecordNotFound::withIdentifier('mandate', '***'),
            'invalid value' => InvalidValue::empty('siret'),
            default => new LogicException('boom'),
        };
    }
}
