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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

final class AccountRefresherTest extends TestCase
{
    public function testAChangedSnapshotIsStoredAndReported(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true, Periodicity::Quarterly));

        $changed = $world->refresher()->refresh($account);

        static::assertTrue($changed);
        $stored = $world->accounts->get(AccountId::fromString('account-1'));
        static::assertTrue($stored->status()->mandatePresent());
        static::assertSame(Periodicity::Quarterly, $stored->periodicity());
        static::assertSame('2026-11-17T09:00:00Z', $stored->lastSyncedAt()->toIso8601());
        static::assertSame(['AccountSynced'], $world->publishedEvents());
        static::assertSame(['call:020', 'begin', 'save:account-1', 'append:urssaf.tdae.account.synced', 'commit', 'publish:AccountSynced'], $world->journal->entries());
        static::assertSame([AccountWorld::SIRET], array_map(static fn(Siret $siret): string => $siret->toString(), $world->gateway->sirets()));
    }

    public function testAnUnchangedSnapshotIsStoredWithoutAnEvent(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $changed = $world->refresher()->refresh($account);

        static::assertFalse($changed);
        static::assertSame('2026-11-17T09:00:00Z', $world->accounts->get(AccountId::fromString('account-1'))->lastSyncedAt()->toIso8601());
        static::assertSame([], $world->publishedEvents());
        static::assertSame(['call:020', 'begin', 'save:account-1', 'append:', 'commit'], $world->journal->entries());
    }

    public function testAFailureOfTheCallStoresNothing(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, UrssafUnavailable::withStatus(Operation::FindAccount, 503, null, ApiErrors::none()));

        try {
            $world->refresher()->refresh($account);
            static::fail('The failure must propagate.');
        } catch (UrssafUnavailable) {
            static::assertSame(['call:020'], $world->journal->entries());
        }
    }

    public function testTheCallIsMadeOutsideTheTransaction(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $world->refresher()->refresh($account);

        static::assertSame('call:020', $world->journal->entries()[0]);
    }

    public function testAConcurrentModificationDiscardsTheAnswer(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true));
        $id = AccountId::fromString('account-1');
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner) use ($id): void {
            $other = $inner->get($id);
            $other->sync(AccountWorld::status(false, Periodicity::Quarterly), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
        });

        try {
            $world->refresher()->refresh($account);
            static::fail('The account changed meanwhile.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }

        static::assertFalse($world->accounts->get(AccountId::fromString('account-1'))->status()->mandatePresent());
        static::assertContains('rollback', $world->journal->entries());
        static::assertSame([], $world->publishedEvents());
    }

    public function testAnAnswerAlreadyReadCanBeApplied(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();

        $changed = $world->refresher()->apply($account, AccountWorld::status(true));

        static::assertTrue($changed);
        static::assertSame([], $world->gateway->calls());
        static::assertTrue($world->accounts->get(AccountId::fromString('account-1'))->status()->mandatePresent());
    }
}
