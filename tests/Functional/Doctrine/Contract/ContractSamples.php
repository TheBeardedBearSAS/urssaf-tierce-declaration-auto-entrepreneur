<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\PaymentSamples;

/**
 * Builders shared by the repository contracts: numbered aggregates (account-1, mandate-1, declaration-1, payment-1) in
 * tenant-1 unless told otherwise, with fictitious SIRET numbers (the number n gives the SIRET 7328293200007n). Aggregates
 * are returned with their events released, except the pending* builders, which keep the creation event. No IBAN: a
 * payment order without one is valid (ADR 0010).
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity One branch per state of the two record state machines.
 */
final class ContractSamples
{
    public const AT = '2026-11-15T10:00:00Z';
    public const TENANT = 'tenant-1';
    public const OTHER_TENANT = 'tenant-2';

    private const SIRET_BASE = 73_282_932_000_074;

    private function __construct() {}

    public static function tenant(string $tenant = self::TENANT): TenantId
    {
        return TenantId::fromString($tenant);
    }

    public static function at(string $iso8601 = self::AT): Instant
    {
        return Instant::fromIso8601($iso8601);
    }

    public static function siret(int $number): Siret
    {
        return Siret::fromString(\sprintf('%014d', self::SIRET_BASE + $number));
    }

    public static function monthly(int $month = 10): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, $month);
    }

    public static function quarterly(int $quarter = 3): DeclarationPeriod
    {
        return QuarterlyPeriod::of(2026, $quarter);
    }

    public static function status(bool $mandatePresent = false, Periodicity $periodicity = Periodicity::Monthly): AccountStatus
    {
        return AccountStatus::of($mandatePresent, $periodicity, CalendarDate::fromString('2019-12-01'), false);
    }

    /**
     * Account n of the SIRET n, synchronised (and created) at $syncedAt; keeps its creation event.
     */
    public static function pendingAccount(int $number, string $tenant = self::TENANT, string $syncedAt = self::AT): AeAccount
    {
        return AeAccount::track(
            AccountId::fromString('account-' . $number),
            self::tenant($tenant),
            self::siret($number),
            self::status(),
            CalendarDate::fromString('1975-10-01'),
            self::at($syncedAt),
        );
    }

    public static function account(int $number, string $tenant = self::TENANT, string $syncedAt = self::AT): AeAccount
    {
        $account = self::pendingAccount($number, $tenant, $syncedAt);
        $account->releaseEvents();

        return $account;
    }

    /**
     * Mandate n of the SIRET $siret, notified at $at; keeps its creation event.
     */
    public static function pendingMandate(
        int $number,
        int $siret,
        string $tenant = self::TENANT,
        MandateOrigin $origin = MandateOrigin::Notified,
        string $at = self::AT,
    ): Mandate {
        $arguments = [
            MandateId::fromString('mandate-' . $number),
            self::tenant($tenant),
            self::siret($siret),
            AccountId::fromString('account-' . $siret),
            self::at($at),
        ];

        return $origin === MandateOrigin::Imported ? Mandate::import(...$arguments) : Mandate::notify(...$arguments);
    }

    public static function mandate(
        int $number,
        int $siret,
        string $tenant = self::TENANT,
        MandateOrigin $origin = MandateOrigin::Notified,
        string $at = self::AT,
    ): Mandate {
        $mandate = self::pendingMandate($number, $siret, $tenant, $origin, $at);
        $mandate->releaseEvents();

        return $mandate;
    }

    /**
     * A mandate cancelled one hour after its notification.
     */
    public static function cancelledMandate(
        int $number,
        int $siret,
        string $tenant = self::TENANT,
        MandateOrigin $origin = MandateOrigin::Notified,
        string $at = self::AT,
    ): Mandate {
        $mandate = self::pendingMandate($number, $siret, $tenant, $origin, $at);
        $mandate->cancel(Instant::fromDateTime(self::at($at)->toDateTimeImmutable()->modify('+1 hour')));
        $mandate->releaseEvents();

        return $mandate;
    }

    /**
     * Pending record n of the SIRET $siret and the period; keeps its creation event.
     */
    public static function pendingDeclaration(
        int $number,
        int $siret,
        DeclarationPeriod $period,
        string $tenant = self::TENANT,
        string $at = self::AT,
    ): DeclarationRecord {
        return DeclarationRecord::record(
            DeclarationRecordId::fromString('declaration-' . $number),
            self::tenant($tenant),
            self::siret($siret),
            $period,
            DeclarationFingerprint::of(self::tenant($tenant), self::siret($siret), $period, DeclarationSamples::turnover()),
            AccountId::fromString('account-' . $siret),
            self::at($at),
        );
    }

    public static function declaration(
        int $number,
        int $siret,
        DeclarationPeriod $period,
        string $tenant = self::TENANT,
        string $at = self::AT,
    ): DeclarationRecord {
        $record = self::pendingDeclaration($number, $siret, $period, $tenant, $at);
        $record->releaseEvents();

        return $record;
    }

    /**
     * A record of the given state, reached through its real transitions: every attempt starts at $at.
     *
     * @param positive-int $attempts
     *
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    public static function declarationIn(
        DeclarationState $state,
        int $number,
        int $siret,
        DeclarationPeriod $period,
        string $tenant = self::TENANT,
        string $at = self::AT,
        int $attempts = 1,
    ): DeclarationRecord {
        $record = self::pendingDeclaration($number, $siret, $period, $tenant, $at);
        $now = self::at($at);
        if ($state !== DeclarationState::Pending) {
            for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
                $record->startSubmission($now);
                if ($attempt < $attempts) {
                    $record->markFailed([], $now);
                    $record->retry($now, $attempts);
                }
            }
        }
        if ($state === DeclarationState::Declared) {
            $record->markDeclared(DeclarationSamples::result(), $now);
        }
        if ($state === DeclarationState::Rejected) {
            $record->markRejected(DeclarationSamples::errors(), $now);
        }
        if ($state === DeclarationState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, [], $now);
        }
        if ($state === DeclarationState::Error) {
            $record->markFailed(DeclarationSamples::errors(), $now);
        }
        if ($state === DeclarationState::InvalidCredentials) {
            $record->markInvalidCredentials($now);
        }
        $record->releaseEvents();

        return $record;
    }

    public static function paymentOrder(DeclarationPeriod $period, int $amount = 1500): PaymentOrder
    {
        return PaymentOrder::of($period, Euros::of($amount), null, PaymentSamples::rum());
    }

    /**
     * Pending payment n of the SIRET $siret, reference $reference and the period; keeps its creation event.
     *
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    public static function pendingPayment(
        int $number,
        int $siret,
        string $reference,
        DeclarationPeriod $period,
        string $tenant = self::TENANT,
        string $at = self::AT,
    ): PaymentRecord {
        return PaymentRecord::record(
            PaymentRecordId::fromString('payment-' . $number),
            self::tenant($tenant),
            PaymentReference::fromString($reference),
            self::siret($siret),
            self::paymentOrder($period),
            AccountId::fromString('account-' . $siret),
            self::at($at),
        );
    }

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    public static function payment(
        int $number,
        int $siret,
        string $reference,
        DeclarationPeriod $period,
        string $tenant = self::TENANT,
        string $at = self::AT,
    ): PaymentRecord {
        $record = self::pendingPayment($number, $siret, $reference, $period, $tenant, $at);
        $record->releaseEvents();

        return $record;
    }

    /**
     * A payment of the given state, reached through its real transitions: every attempt starts at $at.
     *
     * @param positive-int $attempts
     *
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    public static function paymentIn(
        PaymentState $state,
        int $number,
        int $siret,
        string $reference,
        DeclarationPeriod $period,
        string $tenant = self::TENANT,
        string $at = self::AT,
        int $attempts = 1,
    ): PaymentRecord {
        $record = self::pendingPayment($number, $siret, $reference, $period, $tenant, $at);
        $now = self::at($at);
        if ($state !== PaymentState::Pending) {
            for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
                $record->startSubmission($now);
                if ($attempt < $attempts) {
                    $record->markFailed([], $now);
                    $record->retry($now, $attempts);
                }
            }
        }
        if ($state === PaymentState::Paid) {
            $record->markPaid(PaymentSamples::result(), $now);
        }
        if ($state === PaymentState::Rejected) {
            $record->markRejected(PaymentSamples::errors(), $now);
        }
        if ($state === PaymentState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, [], $now);
        }
        if ($state === PaymentState::Error) {
            $record->markFailed(PaymentSamples::errors(), $now);
        }
        if ($state === PaymentState::InvalidCredentials) {
            $record->markInvalidCredentials($now);
        }
        $record->releaseEvents();

        return $record;
    }
}
