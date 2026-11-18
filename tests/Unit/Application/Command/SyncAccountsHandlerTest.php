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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountRefresher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingOutbox;

final class SyncAccountsHandlerTest extends TestCase
{
    public function testEveryOutcomeOfAnAccountIsReportedAndTheRunGoesOn(): void
    {
        $world = new AccountWorld();
        $this->tracked($world, 5);
        $world->gateway->queue(
            Operation::FindAccount,
            AccountWorld::status(true),
            AccountWorld::status(),
            UrssafRequestRejected::withErrors(Operation::FindAccount, 400, ApiErrors::of(ApiError::of('ER_API_020_0001', null, null), ApiError::of('ER_API_020_0002', null, null)), RejectionKind::AccountUnknown),
            UrssafUnavailable::withStatus(Operation::FindAccount, 503, 30, ApiErrors::none()),
            RateLimitExceeded::forOperation(Operation::FindAccount, 5),
        );

        $report = (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));

        static::assertSame(5, $report->total());
        static::assertEquals(
            [
                new BatchItem('account-1', ItemOutcome::Changed),
                new BatchItem('account-2', ItemOutcome::Unchanged),
                new BatchItem('account-3', ItemOutcome::Failed, ['ER_API_020_0001', 'ER_API_020_0002']),
                new BatchItem('account-4', ItemOutcome::RetryLater),
                new BatchItem('account-5', ItemOutcome::RetryLater),
            ],
            $report->items(),
        );
        static::assertSame(1, $report->count(ItemOutcome::Changed));
        static::assertSame(['AccountSynced'], $world->publishedEvents());
    }

    public function testAFailedAccountIsLeftUntouched(): void
    {
        $world = new AccountWorld();
        $this->tracked($world, 2);
        $world->gateway->queue(Operation::FindAccount, UrssafUnavailable::withStatus(Operation::FindAccount, 503, null, ApiErrors::none()), AccountWorld::status());

        (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));

        static::assertSame('2026-11-01T08:00:00Z', $world->accounts->get(AccountId::fromString('account-1'))->lastSyncedAt()->toIso8601());
        static::assertSame('2026-11-17T09:00:00Z', $world->accounts->get(AccountId::fromString('account-2'))->lastSyncedAt()->toIso8601());
    }

    public function testAnAccountChangedMeanwhileIsRetriedLaterWithItsCode(): void
    {
        $world = new AccountWorld();
        $this->tracked($world, 2);
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true), AccountWorld::status());
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner): void {
            $other = $inner->get(AccountId::fromString('account-1'));
            $other->sync(AccountWorld::status(false, Periodicity::Quarterly), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
        });

        $report = (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));

        static::assertEquals(
            [new BatchItem('account-1', ItemOutcome::RetryLater, [AeAccountRepository::CONCURRENT_MODIFICATION]), new BatchItem('account-2', ItemOutcome::Unchanged)],
            $report->items(),
        );
    }

    public function testAnotherViolationStopsTheRun(): void
    {
        $world = new AccountWorld();
        $this->tracked($world, 2);
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(), AccountWorld::status());
        $violation = InvariantViolation::because('outbox.refused', 'Refused.');
        $unitOfWork = new UnitOfWork($world->transactions ?? new ImmediateTransactionManager(), $world->publisher, new IgnoredFailures(), new FailingOutbox($violation));
        $refresher = new AccountRefresher($world->gateway, $world->accounts, $world->clock, $unitOfWork);

        try {
            (new SyncAccountsHandler($world->accounts, $refresher, $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));
            static::fail('The violation must stop the run.');
        } catch (InvariantViolation $thrown) {
            static::assertSame($violation, $thrown);
        }
        static::assertSame(['020'], $world->gateway->calls());
    }

    public function testInvalidCredentialsStopTheRunAfterTheCommittedItems(): void
    {
        $world = new AccountWorld();
        $this->tracked($world, 3);
        $failure = UrssafAuthenticationFailed::withStatus(401);
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true), $failure);

        try {
            (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));
            static::fail('Invalid credentials concern the whole tenant.');
        } catch (UrssafAuthenticationFailed $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(['020', '020'], $world->gateway->calls());
        static::assertTrue($world->accounts->get(AccountId::fromString('account-1'))->status()->mandatePresent());
        static::assertSame('2026-11-01T08:00:00Z', $world->accounts->get(AccountId::fromString('account-2'))->lastSyncedAt()->toIso8601());
        static::assertSame(['AccountSynced'], $world->publishedEvents());
    }

    public function testTheLeastRecentlySyncedAccountsComeFirst(): void
    {
        $world = new AccountWorld();
        $this->tracked($world, 3);
        $recent = $world->accounts->get(AccountId::fromString('account-1'));
        $recent->sync(AccountWorld::status(), Instant::fromIso8601('2026-11-10T08:00:00Z'));
        $world->accounts->add($recent);
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(), AccountWorld::status(), AccountWorld::status());

        $report = (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));

        static::assertSame(['account-2', 'account-3', 'account-1'], array_map(static fn(BatchItem $item): string => $item->subject, $report->items()));
    }

    public function testTheLimitIsCappedByThePolicy(): void
    {
        $policy = (new WorkflowPolicy())->withPageSizes(1, 2);
        $world = new AccountWorld(true, $policy);
        $this->tracked($world, 4);
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(), AccountWorld::status(), AccountWorld::status(), AccountWorld::status());
        $handler = new SyncAccountsHandler($world->accounts, $world->refresher(), $policy);

        static::assertSame(2, $handler(new SyncAccounts(AccountWorld::tenant(), 3))->total());
        static::assertSame(1, $handler(new SyncAccounts(AccountWorld::tenant(), null))->total());
        static::assertSame(1, $handler(new SyncAccounts(AccountWorld::tenant(), 1))->total());
    }

    public function testAnInvalidLimitIsRefused(): void
    {
        $world = new AccountWorld();

        try {
            (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), 0));
            static::fail('The limit must be positive.');
        } catch (InvariantViolation $violation) {
            static::assertSame('workflow_policy.invalid_limit', $violation->violationCode());
        }
    }

    public function testOnlyTheAccountsOfTheTenantAreSynced(): void
    {
        $world = new AccountWorld();

        $report = (new SyncAccountsHandler($world->accounts, $world->refresher(), $world->policy))(new SyncAccounts(AccountWorld::tenant(), null));

        static::assertSame(0, $report->total());
        static::assertSame([], $world->gateway->calls());
    }

    private function tracked(AccountWorld $world, int $count): void
    {
        for ($n = 1; $n <= $count; ++$n) {
            $world->trackedAccount('account-' . $n, AccountWorld::BIRTH_DATE, false, Periodicity::Monthly, '7328293200007' . $n);
        }
    }
}
