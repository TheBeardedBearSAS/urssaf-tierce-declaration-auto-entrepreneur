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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;

/**
 * Keeps the journaling decorators honest until the use cases of lot 4b use them: delegation, journal entries,
 * refused claims and the intrusion that runs once.
 */
final class JournalingRepositoriesTest extends TestCase
{
    public function testTheAccountsDelegateAndJournalSavesAndDeletes(): void
    {
        $journal = new Journal();
        $accounts = new JournalingAeAccounts($journal);
        $accounts->add(ContractSamples::account(1));

        $accounts->save($accounts->get(AccountId::fromString('account-1')));
        $accounts->save(ContractSamples::account(2, ContractSamples::OTHER_TENANT));
        $accounts->delete(AccountId::fromString('account-1'));

        static::assertSame(['save:account-1', 'save:account-2', 'delete:account-1'], $journal->entries());
        static::assertNull($accounts->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1)));
        static::assertSame('account-2', $accounts->findBySiret(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(2))?->id()->value());
        static::assertSame(['account-2'], array_map(static fn(AeAccount $account): string => $account->id()->value(), $accounts->findLeastRecentlySynced(ContractSamples::tenant(ContractSamples::OTHER_TENANT), 10)));
        static::assertSame(['tenant-2'], array_map(static fn(TenantId $tenant): string => $tenant->value(), $accounts->tenants()));
        static::assertSame(['account-2'], array_map(static fn(AeAccount $account): string => $account->id()->value(), $accounts->inner()->all()));
    }

    public function testTheAccountIntrusionRunsOnceBeforeTheNextSave(): void
    {
        $journal = new Journal();
        $accounts = new JournalingAeAccounts($journal);
        $runs = 0;
        $accounts->beforeNextSave(static function () use (&$runs, $journal): void {
            ++$runs;
            $journal->add('intrusion');
        });

        $accounts->save(ContractSamples::account(1));
        $accounts->save(ContractSamples::account(2));

        static::assertSame(1, $runs);
        static::assertSame(['save:account-1', 'intrusion', 'save:account-2'], $journal->entries());
    }

    public function testTheMandatesDelegateAndJournalSavesAndDeletes(): void
    {
        $journal = new Journal();
        $mandates = new JournalingMandates($journal);
        $mandates->add(ContractSamples::mandate(1, 1));

        $mandates->save($mandates->get(MandateId::fromString('mandate-1')));
        $mandates->delete(MandateId::fromString('mandate-1'));

        static::assertSame(['save:mandate-1', 'delete:mandate-1'], $journal->entries());
        static::assertNull($mandates->findActive(ContractSamples::tenant(), ContractSamples::siret(1)));
        static::assertSame([], $mandates->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1)));
        static::assertSame([], $mandates->inner()->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1)));
    }

    public function testTheMandateIntrusionRunsOnceBeforeTheNextSave(): void
    {
        $journal = new Journal();
        $mandates = new JournalingMandates($journal);
        $mandates->beforeNextSave(static function () use ($journal): void {
            $journal->add('intrusion');
        });

        $mandates->save(ContractSamples::mandate(1, 1));
        $mandates->save(ContractSamples::cancelledMandate(2, 1));

        static::assertSame(['save:mandate-1', 'intrusion', 'save:mandate-2'], $journal->entries());
    }

    public function testTheDeclarationsDelegateAndJournalSavesClaimsAndErasures(): void
    {
        $journal = new Journal();
        $records = new JournalingDeclarationRecords($journal);
        $records->add(
            ContractSamples::declaration(1, 1, ContractSamples::monthly()),
            ContractSamples::declarationIn(DeclarationState::Submitting, 2, 2, ContractSamples::monthly()),
        );
        $tenant = ContractSamples::tenant();
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        $records->save($records->get(DeclarationRecordId::fromString('declaration-1')));
        static::assertTrue($records->claimForSubmission(DeclarationRecordId::fromString('declaration-1'), $now));
        static::assertFalse($records->claimForSubmission(DeclarationRecordId::fromString('declaration-1'), $now));
        $deleted = $records->deleteBySiret($tenant, ContractSamples::siret(2), [DeclarationState::Submitting]);

        static::assertSame(['save:declaration-1', 'claim:declaration-1', 'deleteBySiret:1'], $journal->entries());
        static::assertSame(['declaration-2'], array_map(static fn(DeclarationRecordId $id): string => $id->value(), $deleted));
        static::assertSame('declaration-1', $records->findCurrent($tenant, ContractSamples::siret(1), ContractSamples::monthly())?->id()->value());
        static::assertCount(1, $records->findByState($tenant, DeclarationState::Submitting, 10));
        static::assertCount(1, $records->findStaleSubmissions($now, 10));
        static::assertSame(1, $records->countByState($tenant)['submitting']);
        static::assertSame(1, $records->countBySiret($tenant, ContractSamples::siret(1))['submitting']);
        static::assertSame(0, $records->countAcceptedAfterSupersession($tenant));
        static::assertSame(['tenant-1'], array_map(static fn(TenantId $tenant): string => $tenant->value(), $records->tenantsWithState(DeclarationState::Submitting)));
        static::assertCount(1, $records->inner()->all());
    }

    public function testARefusedDeclarationClaimIsLostAndNotJournaled(): void
    {
        $journal = new Journal();
        $records = new JournalingDeclarationRecords($journal);
        $records->add(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $records->refuseClaim($id);

        static::assertFalse($records->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));
        static::assertSame([], $journal->entries());
        static::assertSame(DeclarationState::Pending, $records->get($id)->state());
    }

    public function testTheDeclarationIntrusionRunsOnceBeforeTheNextSave(): void
    {
        $journal = new Journal();
        $records = new JournalingDeclarationRecords($journal);
        $records->beforeNextSave(static function () use ($journal): void {
            $journal->add('intrusion');
        });

        $records->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $records->save(ContractSamples::declaration(2, 2, ContractSamples::monthly()));

        static::assertSame(['save:declaration-1', 'intrusion', 'save:declaration-2'], $journal->entries());
    }

    public function testThePaymentsDelegateAndJournalSavesClaimsAndErasures(): void
    {
        $journal = new Journal();
        $records = new JournalingPaymentRecords($journal);
        $records->add(
            ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()),
            ContractSamples::paymentIn(PaymentState::Submitting, 2, 2, 'payment-ref-2', ContractSamples::monthly()),
        );
        $tenant = ContractSamples::tenant();
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        $records->save($records->get(PaymentRecordId::fromString('payment-1')));
        static::assertTrue($records->claimForSubmission(PaymentRecordId::fromString('payment-1'), $now));
        static::assertFalse($records->claimForSubmission(PaymentRecordId::fromString('payment-1'), $now));
        $deleted = $records->deleteBySiret($tenant, ContractSamples::siret(2), [PaymentState::Submitting]);

        static::assertSame(['save:payment-1', 'claim:payment-1', 'deleteBySiret:1'], $journal->entries());
        static::assertSame(['payment-2'], array_map(static fn(PaymentRecordId $id): string => $id->value(), $deleted));
        static::assertSame('payment-1', $records->findByReference($tenant, PaymentReference::fromString('payment-ref-1'))?->id()->value());
        static::assertCount(1, $records->findCurrentForPeriod($tenant, ContractSamples::siret(1), ContractSamples::monthly(), [PaymentState::Submitting]));
        static::assertCount(1, $records->findByState($tenant, PaymentState::Submitting, 10));
        static::assertCount(1, $records->findStaleSubmissions($now, 10));
        static::assertSame(1, $records->countByState($tenant)['submitting']);
        static::assertSame(1, $records->countBySiret($tenant, ContractSamples::siret(1))['submitting']);
        static::assertSame(['tenant-1'], array_map(static fn(TenantId $tenant): string => $tenant->value(), $records->tenantsWithState(PaymentState::Submitting)));
        static::assertCount(1, $records->inner()->all());
    }

    public function testARefusedPaymentClaimIsLostAndNotJournaled(): void
    {
        $journal = new Journal();
        $records = new JournalingPaymentRecords($journal);
        $records->add(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $records->refuseClaim($id);

        static::assertFalse($records->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));
        static::assertSame([], $journal->entries());
        static::assertSame(PaymentState::Pending, $records->get($id)->state());
    }

    public function testThePaymentIntrusionRunsOnceBeforeTheNextSave(): void
    {
        $journal = new Journal();
        $records = new JournalingPaymentRecords($journal);
        $records->beforeNextSave(static function () use ($journal): void {
            $journal->add('intrusion');
        });

        $records->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $records->save(ContractSamples::payment(2, 2, 'payment-ref-2', ContractSamples::monthly()));

        static::assertSame(['save:payment-1', 'intrusion', 'save:payment-2'], $journal->entries());
    }
}
