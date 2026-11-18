<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Mapping;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\PaymentSamples;

/**
 * Aggregates of the mapping round trips, by case name (see {@see DoctrineMappingTest}): fictitious values only, events
 * released. The record builders return every record of the case (a supersession has two).
 *
 * @mago-expect lint:cyclomatic-complexity One branch per case name of the data providers.
 */
final class DoctrineMappingSamples
{
    private function __construct() {}

    /**
     * @param string $case "tracked", "no-birth-date", "quarterly-bare", "synced" or "birth-remembered"
     */
    public static function account(string $case): AeAccount
    {
        $account = match ($case) {
            'no-birth-date', 'birth-remembered' => AeAccount::track(
                AccountId::fromString('account-1'),
                ContractSamples::tenant(),
                ContractSamples::siret(1),
                ContractSamples::status(),
                null,
                ContractSamples::at(),
            ),
            'quarterly-bare' => AeAccount::track(
                AccountId::fromString('account-1'),
                ContractSamples::tenant(),
                ContractSamples::siret(1),
                AccountStatus::of(true, Periodicity::Quarterly, null, null),
                CalendarDate::fromString('1975-10-01'),
                ContractSamples::at(),
            ),
            default => ContractSamples::pendingAccount(1),
        };
        if ($case === 'synced') {
            $account->sync(ContractSamples::status(true, Periodicity::Quarterly), ContractSamples::at('2026-11-16T10:00:00Z'));
        }
        if ($case === 'birth-remembered') {
            $account->rememberBirthDate(CalendarDate::fromString('1980-02-29'), ContractSamples::at('2026-11-16T10:00:00Z'));
        }
        $account->releaseEvents();

        return $account;
    }

    /**
     * @param string $case "notified", "imported", "cancelled", "recorded" or "no-account"
     */
    public static function mandate(string $case): Mandate
    {
        if ($case === 'imported') {
            return ContractSamples::mandate(1, 1, ContractSamples::TENANT, MandateOrigin::Imported);
        }
        if ($case === 'cancelled') {
            return ContractSamples::cancelledMandate(1, 1);
        }
        if ($case === 'recorded') {
            $mandate = ContractSamples::pendingMandate(1, 1);
            $mandate->recordCancellation(ContractSamples::at('2026-11-16T10:00:00Z'));
            $mandate->releaseEvents();

            return $mandate;
        }
        if ($case === 'no-account') {
            $mandate = Mandate::notify(MandateId::fromString('mandate-1'), ContractSamples::tenant(), ContractSamples::siret(1), null, ContractSamples::at());
            $mandate->releaseEvents();

            return $mandate;
        }

        return ContractSamples::mandate(1, 1);
    }

    /**
     * @param string $case "state:<DeclarationState value>", "unknown-stale", "quarterly", "no-account", "superseded",
     *                     "late-acceptance" or "resolved"
     *
     * @return list<DeclarationRecord>
     */
    public static function declarations(string $case): array
    {
        if (str_starts_with($case, 'state:')) {
            return [ContractSamples::declarationIn(DeclarationState::from(substr($case, \strlen('state:'))), 1, 1, ContractSamples::monthly(), ContractSamples::TENANT, ContractSamples::AT, 2)];
        }
        if ($case === 'unknown-stale') {
            $record = ContractSamples::declarationIn(DeclarationState::Submitting, 1, 1, ContractSamples::monthly());
            $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T12:00:00Z'));
            $record->releaseEvents();

            return [$record];
        }
        if ($case === 'quarterly') {
            return [ContractSamples::declaration(1, 1, ContractSamples::quarterly())];
        }
        if ($case === 'no-account') {
            $record = DeclarationRecord::record(
                DeclarationRecordId::fromString('declaration-1'),
                ContractSamples::tenant(),
                ContractSamples::siret(1),
                ContractSamples::monthly(),
                DeclarationSamples::fingerprint(),
                null,
                ContractSamples::at(),
            );
            $record->releaseEvents();

            return [$record];
        }
        if ($case === 'resolved') {
            $record = ContractSamples::declarationIn(DeclarationState::OutcomeUnknown, 1, 1, ContractSamples::monthly());
            $record->resolveAsDeclared(DeclarationSamples::certificate(), ContractSamples::at('2026-11-16T12:00:00Z'));
            $record->releaseEvents();

            return [$record];
        }

        $old = ContractSamples::pendingDeclaration(1, 1, ContractSamples::monthly());
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T10:00:00Z'));
        if ($case === 'late-acceptance') {
            $old->markDeclared(DeclarationSamples::result(), ContractSamples::at('2026-11-16T11:00:00Z'));
        }
        $old->releaseEvents();
        $new->releaseEvents();

        return [$old, $new];
    }

    /**
     * @param string $case "state:<PaymentState value>", "unknown-stale", "quarterly", "no-rum", "no-account",
     *                     "superseded" or "resolved"
     *
     * @return list<PaymentRecord>
     */
    public static function payments(string $case): array
    {
        if (str_starts_with($case, 'state:')) {
            return [ContractSamples::paymentIn(PaymentState::from(substr($case, \strlen('state:'))), 1, 1, 'INV-1', ContractSamples::monthly(), ContractSamples::TENANT, ContractSamples::AT, 2)];
        }
        if ($case === 'unknown-stale') {
            $record = ContractSamples::paymentIn(PaymentState::Submitting, 1, 1, 'INV-1', ContractSamples::monthly());
            $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T12:00:00Z'));
            $record->releaseEvents();

            return [$record];
        }
        if ($case === 'quarterly') {
            return [ContractSamples::payment(1, 1, 'INV-1', ContractSamples::quarterly())];
        }
        if ($case === 'no-rum' || $case === 'no-account') {
            $record = PaymentRecord::record(
                PaymentRecordId::fromString('payment-1'),
                ContractSamples::tenant(),
                PaymentReference::fromString('INV-1'),
                ContractSamples::siret(1),
                $case === 'no-rum' ? PaymentOrder::of(ContractSamples::monthly(), Euros::of(1_500), null, null) : ContractSamples::paymentOrder(ContractSamples::monthly()),
                $case === 'no-rum' ? AccountId::fromString('account-1') : null,
                ContractSamples::at(),
            );
            $record->releaseEvents();

            return [$record];
        }
        if ($case === 'resolved') {
            $record = ContractSamples::paymentIn(PaymentState::OutcomeUnknown, 1, 1, 'INV-1', ContractSamples::monthly());
            $record->resolveAsPaid(PaymentSamples::certificate(), ContractSamples::at('2026-11-16T12:00:00Z'));
            $record->releaseEvents();

            return [$record];
        }

        $old = ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'INV-1', ContractSamples::monthly());
        $new = $old->supersede(PaymentRecordId::fromString('payment-2'), ContractSamples::paymentOrder(ContractSamples::monthly(), 2_000), ContractSamples::at('2026-11-16T10:00:00Z'));
        $old->releaseEvents();
        $new->releaseEvents();

        return [$old, $new];
    }
}
