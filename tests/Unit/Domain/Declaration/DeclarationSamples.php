<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration;

use Closure;
use DateInterval;
use PHPUnit\Framework\Assert;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * Fictitious builders of declaration records and their inputs, shared by the declaration tests.
 *
 * One builder per sample, shared by every declaration test.
 *
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class DeclarationSamples
{
    public static function instant(): Instant
    {
        return Instant::fromIso8601('2026-11-15T10:00:00Z');
    }

    /**
     * An instant $minutes after the record creation instant.
     */
    public static function later(int $minutes): Instant
    {
        return Instant::fromDateTime(self::instant()->toDateTimeImmutable()->add(new DateInterval('PT' . $minutes . 'M')));
    }

    public static function tenantId(): TenantId
    {
        return TenantId::fromString('tenant-1');
    }

    public static function siret(): Siret
    {
        return Siret::fromString('12345678901234');
    }

    public static function accountId(): AccountId
    {
        return AccountId::fromString('account-1');
    }

    public static function recordId(string $value): DeclarationRecordId
    {
        return DeclarationRecordId::fromString($value);
    }

    public static function period(): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, 10);
    }

    public static function turnover(): Turnover
    {
        return Turnover::of(Euros::of(1000), Euros::of(200), Euros::of(300), Euros::of(400), Euros::of(500));
    }

    public static function otherTurnover(): Turnover
    {
        return Turnover::of(Euros::of(1001), Euros::of(200), Euros::of(300), Euros::of(400), Euros::of(500));
    }

    public static function fingerprint(): DeclarationFingerprint
    {
        return DeclarationFingerprint::of(self::tenantId(), self::siret(), self::period(), self::turnover());
    }

    public static function otherFingerprint(): DeclarationFingerprint
    {
        return DeclarationFingerprint::of(self::tenantId(), self::siret(), self::period(), self::otherTurnover());
    }

    /**
     * A pending record whose creation event was released.
     */
    public static function record(?AccountId $accountId): DeclarationRecord
    {
        $record = DeclarationRecord::record(
            self::recordId('record-1'),
            self::tenantId(),
            self::siret(),
            self::period(),
            self::fingerprint(),
            $accountId,
            self::instant(),
        );
        $record->releaseEvents();

        return $record;
    }

    /**
     * Asserts the action throws a domain exception with the code (the exception is checked, not discarded).
     *
     * @param Closure(): mixed $action
     *
     * @param-immediately-invoked-callable $action
     */
    public static function assertViolation(string $code, Closure $action): void
    {
        $exception = DomainAssertions::assertViolationCode($code, $action);
        Assert::assertSame($code, $exception->violationCode());
    }

    /**
     * Same as {@see self::assertViolation()}, returning the exception to check its parameters.
     *
     * @param Closure(): mixed $action
     *
     * @param-immediately-invoked-callable $action
     */
    public static function assertViolationException(string $code, Closure $action): DomainException
    {
        return DomainAssertions::assertViolationCode($code, $action);
    }

    /**
     * Asserts the action throws an InvalidStateTransition between the two states of the record aggregate.
     *
     * @param Closure(): mixed $action
     *
     * @param-immediately-invoked-callable $action
     */
    public static function assertInvalidTransition(Closure $action, string $from, string $to): void
    {
        try {
            $action();
        } catch (InvalidStateTransition $exception) {
            Assert::assertSame($from, $exception->from());
            Assert::assertSame($to, $exception->to());
            Assert::assertSame('declaration_record', $exception->aggregate());

            return;
        }
        Assert::fail('Expected an InvalidStateTransition.');
    }

    /**
     * @return list<SubmissionError>
     */
    public static function errors(): array
    {
        return [SubmissionError::of('ER_API_050_0070', 'unknown_mandate', 'Mandat inconnu', 'Détail')];
    }

    public static function certificate(): Certificate
    {
        return Certificate::fromString('12345678');
    }

    public static function amounts(): ContributionAmounts
    {
        return ContributionAmounts::of(
            Euros::of(100),
            Euros::of(60),
            null,
            null,
            null,
            null,
            null,
            LiberatoryContributions::none(),
            ChamberFees::none(),
            null,
            null,
        );
    }

    public static function result(): DeclarationResult
    {
        return DeclarationResult::of(
            self::certificate(),
            'W1dl9W2Oa4R6ab3UlQAqxF3',
            CalendarDate::fromString('2026-11-20'),
            self::amounts(),
        );
    }
}
