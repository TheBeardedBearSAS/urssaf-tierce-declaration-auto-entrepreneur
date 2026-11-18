<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use Throwable;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class SyncAccountHandlerTest extends TestCase
{
    public function testTheSnapshotIsStoredAndTheViewShowsIt(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true, Periodicity::Quarterly));

        $view = self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));

        static::assertSame('account-1', $view->accountId);
        static::assertTrue($view->mandatePresent);
        static::assertSame('T', $view->periodicity);
        static::assertSame('2026-11-17T09:00:00Z', $view->lastSyncedAt);
        static::assertSame(['AccountSynced'], $world->publishedEvents());
    }

    public function testTheAccountCanBeDesignatedByItsId(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-1'))));

        static::assertSame('account-1', $view->accountId);
        static::assertSame([], $world->publishedEvents());
    }

    public function testTheActiveMandateIsShown(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));

        static::assertSame('mandate-1', $view->mandate?->mandateId);
        static::assertSame('notified', $view->mandate->state);
    }

    public function testTheLatestMandateIsShownWhenNoneIsActive(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->cancelledMandate('mandate-1');
        $world->cancelledMandate('mandate-2');
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));

        static::assertSame('mandate-2', $view->mandate?->mandateId);
    }

    public function testWithoutAMandateNoneIsShown(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));

        static::assertNull($view->mandate);
    }

    public function testAMissingAccountIsNotFoundBeforeAnyCall(): void
    {
        $world = new AccountWorld();

        try {
            self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));
            static::fail('The account does not exist.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
        }
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnAccountOfAnotherTenantIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $this->expectException(RecordNotFound::class);

        self::handler($world)(new SyncAccount(AccountWorld::tenant('tenant-2'), AccountSelector::byAccountId(AccountId::fromString('account-1'))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'rejected' => ['rejected'];
        yield 'unavailable' => ['unavailable'];
        yield 'rate limit' => ['rate limit'];
        yield 'credentials' => ['credentials'];
    }

    #[DataProvider('failures')]
    public function testEveryFailureOfTheCallPropagatesAndChangesNothing(string $case): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $failure = self::failure($case);
        $world->gateway->queue(Operation::FindAccount, $failure);

        try {
            self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));
            static::fail('The failure must propagate.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(['call:020'], $world->journal->entries());
        static::assertSame('2026-11-01T08:00:00Z', $world->accounts->get(AccountId::fromString('account-1'))->lastSyncedAt()->toIso8601());
    }

    public function testAConcurrentModificationDiscardsTheAnswer(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true));
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner): void {
            $other = $inner->get(AccountId::fromString('account-1'));
            $other->sync(AccountWorld::status(false, Periodicity::Quarterly), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
        });

        try {
            self::handler($world)(new SyncAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));
            static::fail('The account changed meanwhile.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertFalse($world->accounts->get(AccountId::fromString('account-1'))->status()->mandatePresent());
    }

    private static function handler(AccountWorld $world): SyncAccountHandler
    {
        return new SyncAccountHandler($world->locator(), $world->refresher(), $world->accounts, $world->mandates);
    }

    private static function failure(string $case): Throwable
    {
        return match ($case) {
            'rejected' => UrssafRequestRejected::withErrors(Operation::FindAccount, 400, ApiErrors::none(), RejectionKind::AccountUnknown),
            'unavailable' => UrssafUnavailable::withStatus(Operation::FindAccount, 503, 30, ApiErrors::none()),
            'rate limit' => RateLimitExceeded::forOperation(Operation::FindAccount, 5),
            default => UrssafAuthenticationFailed::withStatus(401),
        };
    }
}
