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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Behaviour every {@see AeAccountRepository} must have, independently of the storage. Identity-related behaviour
 * (copies versus managed instances) is deliberately not part of the contract. One method per scenario.
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 * @mago-expect lint:too-many-methods
 */
trait AeAccountRepositoryContract
{
    protected AeAccountRepository $repository;

    abstract protected function createRepository(): AeAccountRepository;

    /**
     * An independent writer over the same store: another process, another EntityManager. The in-memory adapter has one
     * store per repository and returns the repository itself. Competing copies are read, and saved, through it.
     */
    abstract protected function anotherWriter(): AeAccountRepository;

    protected function setUp(): void
    {
        $this->repository = $this->createRepository();
    }

    /**
     * @param list<AeAccount> $accounts
     *
     * @return list<string>
     */
    protected static function accountIds(array $accounts): array
    {
        return array_map(static fn(AeAccount $account): string => $account->id()->value(), $accounts);
    }

    public function testASavedAccountIsReadBack(): void
    {
        $this->repository->save(ContractSamples::account(1, ContractSamples::TENANT, '2026-11-10T09:00:00Z'));

        $read = $this->repository->get(AccountId::fromString('account-1'));

        static::assertSame('account-1', $read->id()->value());
        static::assertSame('tenant-1', $read->tenantId()->value());
        static::assertTrue($read->siret()->equals(ContractSamples::siret(1)));
        static::assertSame('1975-10-01', $read->birthDate()?->toString());
        static::assertFalse($read->status()->mandatePresent());
        static::assertSame(Periodicity::Monthly, $read->status()->periodicity());
        static::assertSame('2019-12-01', $read->status()->affiliationDate()?->toString());
        static::assertFalse($read->status()->acreExemption());
        static::assertSame('2026-11-10T09:00:00Z', $read->createdAt()->toIso8601());
        static::assertSame('2026-11-10T09:00:00Z', $read->lastSyncedAt()->toIso8601());
        static::assertSame('2026-11-10T09:00:00Z', $read->updatedAt()->toIso8601());
    }

    public function testAnAccountWithoutBirthDateAndWithoutOptionalStatusFieldsIsReadBack(): void
    {
        $account = AeAccount::track(
            AccountId::fromString('account-1'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            AccountStatus::of(true, Periodicity::Quarterly, null, null),
            null,
            ContractSamples::at(),
        );
        $this->repository->save($account);

        $read = $this->repository->get($account->id());

        static::assertNull($read->birthDate());
        static::assertTrue($read->status()->mandatePresent());
        static::assertSame(Periodicity::Quarterly, $read->periodicity());
        static::assertNull($read->status()->affiliationDate());
        static::assertNull($read->status()->acreExemption());
    }

    public function testTheEventsAreNeitherPersistedNorConsumedFromTheCaller(): void
    {
        $account = ContractSamples::pendingAccount(1);

        $this->repository->save($account);

        static::assertCount(1, $account->releaseEvents());
        static::assertSame([], $this->repository->get($account->id())->releaseEvents());
    }

    public function testASavedSynchronisationAndBirthDateAreReadBack(): void
    {
        $this->repository->save(ContractSamples::account(1, ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $stored = $this->repository->get(AccountId::fromString('account-1'));
        $stored->sync(AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), true), ContractSamples::at('2026-11-12T08:30:00Z'));
        $stored->rememberBirthDate(CalendarDate::fromString('1980-02-03'), ContractSamples::at('2026-11-12T08:31:00Z'));

        $this->repository->save($stored);
        $read = $this->repository->get($stored->id());

        static::assertTrue($read->status()->mandatePresent());
        static::assertSame(Periodicity::Quarterly, $read->periodicity());
        static::assertTrue($read->status()->acreExemption());
        static::assertSame('1980-02-03', $read->birthDate()?->toString());
        static::assertSame('2026-11-12T08:30:00Z', $read->lastSyncedAt()->toIso8601());
        static::assertSame('2026-11-12T08:31:00Z', $read->updatedAt()->toIso8601());
        static::assertSame('2026-11-10T09:00:00Z', $read->createdAt()->toIso8601());
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        try {
            $this->repository->get(AccountId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $exception) {
            static::assertSame('ae_account.not_found', $exception->violationCode());
            static::assertSame('missing', $exception->identifier());
        }
    }

    public function testTheSameSiretOfAnotherAccountOfTheTenantIsRefused(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $duplicate = AeAccount::track(
            AccountId::fromString('account-2'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        );

        try {
            $this->repository->save($duplicate);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.duplicate_siret', $exception->violationCode());
            static::assertSame(AeAccountRepository::DUPLICATE_SIRET, $exception->violationCode());
            // The SIRET is personal data (ADR 0010): never in an exception.
            static::assertSame([], $exception->params());
            static::assertStringNotContainsString(ContractSamples::siret(1)->toString(), $exception->getMessage());
        }
    }

    public function testTheSameSiretInAnotherTenantIsAccepted(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $this->repository->save(AeAccount::track(
            AccountId::fromString('account-2'),
            ContractSamples::tenant(ContractSamples::OTHER_TENANT),
            ContractSamples::siret(1),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        ));

        static::assertSame('account-1', $this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1))?->id()->value());
        static::assertSame('account-2', $this->repository->findBySiret(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1))?->id()->value());
    }

    public function testSavingAnExistingAccountAgainIsAnUpdateNotADuplicate(): void
    {
        $account = ContractSamples::account(1);
        $this->repository->save($account);
        $this->repository->save($account);

        static::assertSame(['account-1'], self::accountIds($this->repository->findLeastRecentlySynced(ContractSamples::tenant(), 10)));
    }

    public function testFindBySiretIsTenantScopedAndReturnsNullWhenAbsent(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $tenant = ContractSamples::tenant();

        static::assertSame('account-1', $this->repository->findBySiret($tenant, ContractSamples::siret(1))?->id()->value());
        static::assertNull($this->repository->findBySiret(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1)));
        static::assertNull($this->repository->findBySiret($tenant, ContractSamples::siret(2)));
    }

    public function testTheLeastRecentlySyncedComeFirstWithTiesByIdWithinTheLimitAndTheTenant(): void
    {
        $this->repository->save(ContractSamples::account(1, ContractSamples::TENANT, '2026-11-12T10:00:00Z'));
        $this->repository->save(ContractSamples::account(2, ContractSamples::TENANT, '2026-11-11T10:00:00Z'));
        $this->repository->save(ContractSamples::account(4, ContractSamples::TENANT, '2026-11-10T10:00:00Z'));
        $this->repository->save(ContractSamples::account(3, ContractSamples::TENANT, '2026-11-10T10:00:00Z'));
        $this->repository->save(ContractSamples::account(5, ContractSamples::OTHER_TENANT, '2026-11-01T10:00:00Z'));
        $tenant = ContractSamples::tenant();

        static::assertSame(['account-3', 'account-4', 'account-2', 'account-1'], self::accountIds($this->repository->findLeastRecentlySynced($tenant, 10)));
        static::assertSame(['account-3', 'account-4'], self::accountIds($this->repository->findLeastRecentlySynced($tenant, 2)));
        static::assertSame(['account-5'], self::accountIds($this->repository->findLeastRecentlySynced(ContractSamples::tenant(ContractSamples::OTHER_TENANT), 10)));
        static::assertSame([], $this->repository->findLeastRecentlySynced(ContractSamples::tenant('tenant-9'), 10));
    }

    public function testTheTenantsAreDistinctAndSorted(): void
    {
        static::assertSame([], $this->repository->tenants());

        $this->repository->save(ContractSamples::account(1, 'tenant-b'));
        $this->repository->save(ContractSamples::account(2, 'tenant-a'));
        $this->repository->save(ContractSamples::account(3, 'tenant-b'));

        static::assertSame(
            ['tenant-a', 'tenant-b'],
            array_map(static fn(TenantId $tenant): string => $tenant->value(), $this->repository->tenants()),
        );
    }

    public function testADeletedAccountIsGoneAndDeletingAMissingOneIsANoOp(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $this->repository->save(ContractSamples::account(2));

        $this->repository->delete(AccountId::fromString('account-1'));
        $this->repository->delete(AccountId::fromString('missing'));

        static::assertNull($this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1)));
        static::assertSame(['account-2'], self::accountIds($this->repository->findLeastRecentlySynced(ContractSamples::tenant(), 10)));
        $this->expectException(RecordNotFound::class);
        $this->repository->get(AccountId::fromString('account-1'));
    }

    public function testDeletingAnAccountFreesItsSiret(): void
    {
        $this->repository->save(ContractSamples::account(1));

        $this->repository->delete(AccountId::fromString('account-1'));
        $replacement = AeAccount::track(
            AccountId::fromString('account-9'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        );
        $this->repository->save($replacement);

        static::assertSame('account-9', $this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1))?->id()->value());
    }

    public function testACopyChangedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $id = AccountId::fromString('account-1');
        $first = $this->repository->get($id);
        $second = $this->anotherWriter()->get($id);
        $first->sync(ContractSamples::status(true), ContractSamples::at('2026-11-16T09:00:00Z'));
        $second->sync(ContractSamples::status(true), ContractSamples::at('2026-11-17T09:00:00Z'));
        $this->repository->save($first);

        try {
            $this->anotherWriter()->save($second);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.concurrent_modification', $exception->violationCode());
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $exception->violationCode());
        }
        static::assertSame('2026-11-16T09:00:00Z', $this->repository->get($id)->lastSyncedAt()->toIso8601());
    }

    public function testACopyReadAgainAfterTheOtherWriterCanBeSavedAndSavedAgain(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $id = AccountId::fromString('account-1');
        $first = $this->repository->get($id);
        $first->sync(ContractSamples::status(true), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($first);

        $second = $this->anotherWriter()->get($id);
        $second->sync(ContractSamples::status(true, Periodicity::Quarterly), ContractSamples::at('2026-11-17T09:00:00Z'));
        $this->anotherWriter()->save($second);
        // The instance that was saved is current: it can be saved again.
        $second->sync(ContractSamples::status(false, Periodicity::Quarterly), ContractSamples::at('2026-11-18T09:00:00Z'));
        $this->anotherWriter()->save($second);

        static::assertSame('2026-11-18T09:00:00Z', $this->repository->get($id)->lastSyncedAt()->toIso8601());
        static::assertSame(Periodicity::Quarterly, $this->repository->get($id)->periodicity());
    }

    public function testACopyErasedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $id = AccountId::fromString('account-1');
        $stale = $this->repository->get($id);
        $stale->sync(ContractSamples::status(true), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->delete($id);

        $this->assertSaveIsRefusedAsConcurrent($stale);
        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testACopyFoundByAQueryIsCheckedLikeACopyReadById(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $tenant = ContractSamples::tenant();
        $found = [
            $this->repository->findBySiret($tenant, ContractSamples::siret(1)),
            $this->repository->findLeastRecentlySynced($tenant, 10)[0] ?? null,
        ];
        $other = $this->anotherWriter()->get(AccountId::fromString('account-1'));
        $other->sync(ContractSamples::status(true), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->save($other);

        foreach ($found as $stale) {
            static::assertNotNull($stale);
            $this->assertSaveIsRefusedAsConcurrent($stale);
        }
    }

    private function assertSaveIsRefusedAsConcurrent(AeAccount $stale): void
    {
        try {
            $this->repository->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.concurrent_modification', $exception->violationCode());
        }
    }
}
