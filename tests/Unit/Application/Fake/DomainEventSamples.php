<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateCancellationOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Fictitious domain events, one factory per lot-1 event (ADR 0010: no real identifier). Fixed values: tenant "tenant-1",
 * account "account-1", mandate "mandate-1", records "declaration-1" / "payment-1", reference "payment-ref-1", instant
 * 2026-11-15T10:00:00Z, period October 2026, certificat 12345678, RUM "RUM-1".
 *
 * @mago-expect lint:too-many-methods One factory per domain event and variant.
 */
final class DomainEventSamples
{
    public const AT = '2026-11-15T10:00:00Z';

    private static function at(): Instant
    {
        return Instant::fromIso8601(self::AT);
    }

    private static function tenant(): TenantId
    {
        return TenantId::fromString('tenant-1');
    }

    private static function account(): AccountId
    {
        return AccountId::fromString('account-1');
    }

    private static function period(): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, 10);
    }

    private static function declaration(): DeclarationRecordId
    {
        return DeclarationRecordId::fromString('declaration-1');
    }

    private static function payment(): PaymentRecordId
    {
        return PaymentRecordId::fromString('payment-1');
    }

    private static function reference(): PaymentReference
    {
        return PaymentReference::fromString('payment-ref-1');
    }

    public static function accountSynced(): AccountSynced
    {
        return new AccountSynced(self::account(), self::tenant(), false, true, Periodicity::Quarterly, Periodicity::Monthly, self::at());
    }

    public static function accountSyncedFirstRead(): AccountSynced
    {
        return new AccountSynced(self::account(), self::tenant(), null, false, null, Periodicity::Quarterly, self::at());
    }

    public static function accountSyncedWithMandateRemoved(): AccountSynced
    {
        return new AccountSynced(self::account(), self::tenant(), true, false, Periodicity::Monthly, Periodicity::Quarterly, self::at());
    }

    public static function declarationOutcomeUnknownWithoutAccount(): DeclarationOutcomeUnknown
    {
        return new DeclarationOutcomeUnknown(self::declaration(), self::tenant(), null, self::period(), OutcomeUnknownReason::StaleSubmission, self::at());
    }

    public static function paymentOutcomeUnknownWithoutAccount(): PaymentOutcomeUnknown
    {
        return new PaymentOutcomeUnknown(self::payment(), self::tenant(), self::reference(), null, self::period(), OutcomeUnknownReason::MayHaveReachedUrssaf, self::at());
    }

    public static function accountForgotten(): AccountForgotten
    {
        return new AccountForgotten(
            self::account(),
            self::tenant(),
            [MandateId::fromString('mandate-1'), MandateId::fromString('mandate-2')],
            [self::declaration()],
            [self::payment(), PaymentRecordId::fromString('payment-2')],
            self::at(),
        );
    }

    public static function accountForgottenWithoutAccount(): AccountForgotten
    {
        return new AccountForgotten(null, self::tenant(), [], [], [], self::at());
    }

    public static function mandateNotified(): MandateNotified
    {
        return new MandateNotified(MandateId::fromString('mandate-1'), self::tenant(), self::account(), MandateOrigin::Notified, self::at());
    }

    public static function mandateNotifiedOfUntrackedAccount(): MandateNotified
    {
        return new MandateNotified(MandateId::fromString('mandate-1'), self::tenant(), null, MandateOrigin::Imported, self::at());
    }

    public static function mandateCancelled(): MandateCancelled
    {
        return new MandateCancelled(MandateId::fromString('mandate-1'), self::tenant(), self::account(), MandateCancellationOrigin::Cancelled, self::at());
    }

    public static function mandateCancelledOfUntrackedAccount(): MandateCancelled
    {
        return new MandateCancelled(MandateId::fromString('mandate-1'), self::tenant(), null, MandateCancellationOrigin::Recorded, self::at());
    }

    public static function declarationRecorded(): DeclarationRecorded
    {
        return new DeclarationRecorded(self::declaration(), self::tenant(), self::account(), self::period(), null, self::at());
    }

    public static function declarationRequeued(): DeclarationRequeued
    {
        return new DeclarationRequeued(self::declaration(), self::tenant(), DeclarationState::Error, self::at());
    }

    public static function declarationSubmissionFailed(): DeclarationSubmissionFailed
    {
        return new DeclarationSubmissionFailed(self::declaration(), self::tenant(), ['TECH_1'], self::at());
    }

    public static function declarationCredentialsRejected(): DeclarationCredentialsRejected
    {
        return new DeclarationCredentialsRejected(self::declaration(), self::tenant(), self::at());
    }

    public static function declarationDeclared(): DeclarationDeclared
    {
        return new DeclarationDeclared(self::declaration(), self::tenant(), self::account(), self::period(), Certificate::fromString('12345678'), 'decl-9', self::at());
    }

    public static function declarationDeclaredWithoutId(): DeclarationDeclared
    {
        return new DeclarationDeclared(self::declaration(), self::tenant(), null, MonthlyPeriod::of(2026, 10), Certificate::fromString('12345678'), null, self::at());
    }

    public static function declarationDeclaredOfUntrackedAccount(): DeclarationDeclared
    {
        return new DeclarationDeclared(self::declaration(), self::tenant(), null, self::period(), Certificate::fromString('12345678'), 'decl-9', self::at());
    }

    public static function declarationRejected(): DeclarationRejected
    {
        return new DeclarationRejected(self::declaration(), self::tenant(), self::account(), self::period(), ['ERR_A', 'ERR_B'], self::at());
    }

    public static function declarationRejectedOfUntrackedAccount(): DeclarationRejected
    {
        return new DeclarationRejected(self::declaration(), self::tenant(), null, QuarterlyPeriod::of(2026, 3), ['ERR_A'], self::at());
    }

    public static function declarationOutcomeUnknown(): DeclarationOutcomeUnknown
    {
        return new DeclarationOutcomeUnknown(self::declaration(), self::tenant(), self::account(), self::period(), OutcomeUnknownReason::StaleSubmission, self::at());
    }

    public static function paymentRecorded(): PaymentRecorded
    {
        return new PaymentRecorded(self::payment(), self::tenant(), self::reference(), self::account(), self::period(), null, self::at());
    }

    public static function paymentRequeued(): PaymentRequeued
    {
        return new PaymentRequeued(self::payment(), self::tenant(), PaymentState::Error, self::at());
    }

    public static function paymentSubmissionFailed(): PaymentSubmissionFailed
    {
        return new PaymentSubmissionFailed(self::payment(), self::tenant(), ['TECH_1'], self::at());
    }

    public static function paymentCredentialsRejected(): PaymentCredentialsRejected
    {
        return new PaymentCredentialsRejected(self::payment(), self::tenant(), self::at());
    }

    public static function paymentOrdered(): PaymentOrdered
    {
        return new PaymentOrdered(
            self::payment(),
            self::tenant(),
            self::reference(),
            self::account(),
            self::period(),
            Certificate::fromString('12345678'),
            'pay-9',
            Rum::fromString('RUM-1'),
            self::at(),
        );
    }

    public static function paymentOrderedWithoutIdOrRum(): PaymentOrdered
    {
        return new PaymentOrdered(self::payment(), self::tenant(), self::reference(), null, self::period(), Certificate::fromString('12345678'), null, null, self::at());
    }

    public static function paymentRejected(): PaymentRejected
    {
        return new PaymentRejected(self::payment(), self::tenant(), self::reference(), self::account(), self::period(), ['ERR_A', 'ERR_B'], self::at());
    }

    public static function paymentRejectedOfUntrackedAccount(): PaymentRejected
    {
        return new PaymentRejected(self::payment(), self::tenant(), self::reference(), null, QuarterlyPeriod::of(2026, 3), ['ERR_A'], self::at());
    }

    public static function paymentOutcomeUnknown(): PaymentOutcomeUnknown
    {
        return new PaymentOutcomeUnknown(self::payment(), self::tenant(), self::reference(), self::account(), self::period(), OutcomeUnknownReason::MayHaveReachedUrssaf, self::at());
    }
}
