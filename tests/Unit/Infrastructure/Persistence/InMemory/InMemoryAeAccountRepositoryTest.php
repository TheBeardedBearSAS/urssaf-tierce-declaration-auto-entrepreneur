<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Persistence\InMemory;

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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;

/**
 * @mago-expect lint:too-many-methods One test per rule of the repository.
 */
final class InMemoryAeAccountRepositoryTest extends TestCase
{
    private InMemoryAeAccountRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAeAccountRepository();
    }

    public function testASavedAccountIsReadBackAsACopy(): void
    {
        $account = Records::account('a1');
        $this->repository->save($account);

        $read = $this->repository->get(AccountId::fromString('a1'));

        static::assertNotSame($account, $read);
        static::assertEquals($account, $read);
        static::assertNotSame($read, $this->repository->get(AccountId::fromString('a1')));
    }

    public function testAChangeIsOnlyVisibleOnceSaved(): void
    {
        $this->repository->save(Records::account('a1'));
        $read = $this->repository->get(AccountId::fromString('a1'));

        $read->sync(AccountStatus::of(true, Periodicity::Quarterly, CalendarDate::fromString('2019-12-01'), false), Records::at(30));

        static::assertFalse($this->repository->get(AccountId::fromString('a1'))->status()->mandatePresent());
        $this->repository->save($read);
        static::assertTrue($this->repository->get(AccountId::fromString('a1'))->status()->mandatePresent());
    }

    public function testTheEventsAreNeitherPersistedNorConsumed(): void
    {
        $account = AeAccount::track(
            AccountId::fromString('a1'),
            Records::tenant(),
            Records::siret(),
            AccountStatus::of(false, Periodicity::Monthly, null, null),
            null,
            Records::at(),
        );

        $this->repository->save($account);

        static::assertCount(1, $account->releaseEvents());
        static::assertSame([], $this->repository->get(AccountId::fromString('a1'))->releaseEvents());
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        try {
            $this->repository->get(AccountId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
            static::assertSame('missing', $notFound->identifier());
        }
    }

    public function testAnotherAccountOfTheSameTenantAndSiretIsRefusedWithoutTheSiret(): void
    {
        $this->repository->save(Records::account('a1'));

        try {
            $this->repository->save(Records::account('a2'));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::DUPLICATE_SIRET, $violation->violationCode());
            static::assertSame([], $violation->params());
            static::assertStringNotContainsString(Records::siret(1)->toString(), $violation->getMessage());
        }
        static::assertCount(1, $this->repository->all());
    }

    public function testTheSameSiretIsAcceptedInAnotherTenantAndAnotherSiretInTheSameTenant(): void
    {
        $this->repository->save(Records::account('a1'));
        $this->repository->save(Records::account('a2', Slot::new()->tenant('tenant-2')));
        $this->repository->save(Records::account('a3', Slot::new()->siret(2)));

        static::assertCount(3, $this->repository->all());
    }

    public function testSavingTheSameAccountAgainIsAnUpdate(): void
    {
        $account = Records::account('a1');
        $this->repository->save($account);
        $this->repository->save($account);

        static::assertCount(1, $this->repository->all());
    }

    public function testFindBySiretIsScopedToTheTenant(): void
    {
        $this->repository->save(Records::account('a1'));
        $this->repository->save(Records::account('a2', Slot::new()->tenant('tenant-2')));
        $this->repository->save(Records::account('a3', Slot::new()->siret(2)));

        static::assertSame('a2', $this->repository->findBySiret(Records::tenant('tenant-2'), Records::siret(1))?->id()->value());
        static::assertSame('a3', $this->repository->findBySiret(Records::tenant('tenant-1'), Records::siret(2))?->id()->value());
        static::assertNotSame(
            $this->repository->findBySiret(Records::tenant('tenant-1'), Records::siret(1)),
            $this->repository->findBySiret(Records::tenant('tenant-1'), Records::siret(1)),
        );
        static::assertNull($this->repository->findBySiret(Records::tenant('tenant-1'), Records::siret(3)));
        static::assertNull($this->repository->findBySiret(Records::tenant('tenant-3'), Records::siret(1)));
    }

    public function testTheLeastRecentlySyncedComeFirstTiesByIdAndAreLimited(): void
    {
        $this->repository->save(Records::account('a3', Slot::new()->siret(3)->minutes(5)));
        $this->repository->save(Records::account('a2', Slot::new()->siret(2)->minutes(5)));
        $this->repository->save(Records::account('a1', Slot::new()->minutes(9)));
        $this->repository->save(Records::account('a0', Slot::new()->siret(4)->minutes(1)));
        $this->repository->save(Records::account('other', Slot::new()->siret(5)->tenant('tenant-2')));

        static::assertSame(
            ['a0', 'a2', 'a3', 'a1'],
            self::ids($this->repository->findLeastRecentlySynced(Records::tenant(), 10)),
        );
        static::assertSame(['a0', 'a2'], self::ids($this->repository->findLeastRecentlySynced(Records::tenant(), 2)));
        static::assertSame([], $this->repository->findLeastRecentlySynced(Records::tenant('tenant-3'), 10));
    }

    public function testTheTenantsAreDistinctAndSorted(): void
    {
        $this->repository->save(Records::account('a1', Slot::new()->tenant('tenant-b')));
        $this->repository->save(Records::account('a2', Slot::new()->siret(2)->tenant('tenant-a')));
        $this->repository->save(Records::account('a3', Slot::new()->siret(3)->tenant('tenant-b')));

        static::assertSame(
            ['tenant-a', 'tenant-b'],
            array_map(static fn(TenantId $tenant): string => $tenant->value(), $this->repository->tenants()),
        );
        static::assertSame([], (new InMemoryAeAccountRepository())->tenants());
    }

    public function testDeleteErasesTheAccountAndAMissingOneIsIgnored(): void
    {
        $this->repository->save(Records::account('a1'));

        $this->repository->delete(AccountId::fromString('a1'));
        $this->repository->delete(AccountId::fromString('a1'));

        static::assertSame([], $this->repository->all());
        $this->expectException(RecordNotFound::class);
        $this->repository->get(AccountId::fromString('a1'));
    }

    public function testADeletedSiretCanBeTrackedAgain(): void
    {
        $this->repository->save(Records::account('a1'));
        $this->repository->delete(AccountId::fromString('a1'));

        $this->repository->save(Records::account('a2'));

        static::assertSame(['a2'], self::ids($this->repository->all()));
    }

    public function testAStaleCopyIsRefused(): void
    {
        $this->repository->save(Records::account('a1'));
        $first = $this->repository->get(AccountId::fromString('a1'));
        $second = $this->repository->get(AccountId::fromString('a1'));
        $this->repository->save($first);

        $this->assertConcurrent($second);
        $this->repository->save($first);
    }

    public function testACopyReReadAfterTheOtherWriterCanBeSavedTwice(): void
    {
        $this->repository->save(Records::account('a1'));
        $this->repository->save($this->repository->get(AccountId::fromString('a1')));

        $fresh = $this->repository->get(AccountId::fromString('a1'));
        $this->repository->save($fresh);
        $this->repository->save($fresh);

        static::assertCount(1, $this->repository->all());
    }

    public function testAnErasedCopyIsRefused(): void
    {
        $this->repository->save(Records::account('a1'));
        $read = $this->repository->get(AccountId::fromString('a1'));
        $this->repository->delete(AccountId::fromString('a1'));

        $this->assertConcurrent($read);
    }

    public function testANeverReadInstanceIsRefusedForAStoredIdentifier(): void
    {
        $this->repository->save(Records::account('a1'));

        $this->assertConcurrent(Records::account('a1', Slot::new()->siret(2)));
    }

    public function testACopyFromAQueryIsCheckedLikeAGetCopy(): void
    {
        $this->repository->save(Records::account('a1'));
        $queried = $this->repository->findLeastRecentlySynced(Records::tenant(), 1)[0];
        $this->repository->save($this->repository->get(AccountId::fromString('a1')));

        $this->assertConcurrent($queried);
    }

    public function testACopyFromFindBySiretAndAllIsCheckedLikeAGetCopy(): void
    {
        $this->repository->save(Records::account('a1'));
        $bySiret = $this->repository->findBySiret(Records::tenant(), Records::siret());
        $all = $this->repository->all()[0];

        static::assertInstanceOf(AeAccount::class, $bySiret);
        $this->repository->save($bySiret);
        $this->assertConcurrent($all);
    }

    private function assertConcurrent(AeAccount $account): void
    {
        try {
            $this->repository->save($account);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
            static::assertSame([], $violation->params());
        }
    }

    /**
     * @param list<AeAccount> $accounts
     *
     * @return list<string>
     */
    private static function ids(array $accounts): array
    {
        return array_map(static fn(AeAccount $account): string => $account->id()->value(), $accounts);
    }
}
