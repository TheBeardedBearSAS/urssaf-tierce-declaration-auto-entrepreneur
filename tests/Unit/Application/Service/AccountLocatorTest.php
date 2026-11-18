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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class AccountLocatorTest extends TestCase
{
    public function testLocatesByAccountId(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $account = $world->locator()->locate(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-1')));

        static::assertSame('account-1', $account->id()->value());
    }

    public function testLocatesBySiret(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $account = $world->locator()->locate(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()));

        static::assertSame('account-1', $account->id()->value());
    }

    public function testAnUnknownSiretIsNotFoundWithoutRevealingTheSiret(): void
    {
        $world = new AccountWorld();

        try {
            $world->locator()->locate(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()));
            static::fail('The account does not exist.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
            static::assertSame('***', $notFound->identifier());
            static::assertStringNotContainsString(AccountWorld::SIRET, $notFound->getMessage());
        }
    }

    public function testAnUnknownAccountIdIsNotFound(): void
    {
        $world = new AccountWorld();

        try {
            $world->locator()->locate(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-9')));
            static::fail('The account does not exist.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
            static::assertSame('account-9', $notFound->identifier());
        }
    }

    public function testAnAccountOfAnotherTenantIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        try {
            $world->locator()->locate(AccountWorld::tenant('tenant-2'), AccountSelector::byAccountId(AccountId::fromString('account-1')));
            static::fail('The account belongs to another tenant.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
            static::assertSame('account-1', $notFound->identifier());
        }
        $this->expectException(RecordNotFound::class);
        $world->locator()->locate(AccountWorld::tenant('tenant-2'), AccountSelector::bySiret(AccountWorld::siret()));
    }

    public function testFindReturnsNullForAnUntrackedSiret(): void
    {
        $world = new AccountWorld();

        static::assertNull($world->locator()->find(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));
    }

    public function testFindReturnsTheTrackedAccountOfASiretOrOfAnId(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $bySiret = $world->locator()->find(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()));
        $byId = $world->locator()->find(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-1')));

        static::assertInstanceOf(AeAccount::class, $bySiret);
        static::assertInstanceOf(AeAccount::class, $byId);
        static::assertSame('account-1', $bySiret->id()->value());
        static::assertSame('account-1', $byId->id()->value());
    }

    public function testFindStillThrowsForAnAccountIdThatMatchesNothing(): void
    {
        $world = new AccountWorld();

        $this->expectException(RecordNotFound::class);

        $world->locator()->find(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-9')));
    }

    public function testTheSiretOfASiretSelectorIsItselfEvenWhenUntracked(): void
    {
        $world = new AccountWorld();

        $siret = $world->locator()->siretOf(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()));

        static::assertSame(AccountWorld::SIRET, $siret->toString());
    }

    public function testTheSiretOfAnAccountIdIsTheOneOfTheAccount(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $siret = $world->locator()->siretOf(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-1')));

        static::assertSame(AccountWorld::SIRET, $siret->toString());
    }

    public function testTheSiretOfAnAccountIdOfAnotherTenantIsNotFound(): void
    {
        $world = new AccountWorld();
        $other = AeAccount::track(
            AccountId::fromString('account-2'),
            AccountWorld::tenant('tenant-2'),
            AccountWorld::siret(),
            AccountWorld::status(),
            null,
            Instant::fromIso8601(AccountWorld::NOW),
        );
        $world->accounts->add($other);

        $this->expectException(RecordNotFound::class);

        $world->locator()->siretOf(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-2')));
    }
}
