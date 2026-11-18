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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\EventfulAccounts;
use Throwable;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class TrackAccountHandlerTest extends TestCase
{
    public function testANewSiretIsTrackedWithItsSnapshotAndItsBirthDate(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status(true, Periodicity::Quarterly));

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('id-1', $view->accountId);
        static::assertSame('tenant-1', $view->tenantId);
        static::assertSame(AccountWorld::SIRET, $view->siret);
        static::assertTrue($view->hasBirthDate);
        static::assertTrue($view->mandatePresent);
        static::assertSame('T', $view->periodicity);
        static::assertSame('2025-03-01', $view->affiliationDate);
        static::assertNull($view->mandate);
        static::assertSame('2026-11-17T09:00:00Z', $view->createdAt);
        static::assertSame('2026-11-17T09:00:00Z', $view->lastSyncedAt);
        $stored = $world->accounts->get(AccountId::fromString('id-1'));
        static::assertSame('1975-02-23', $stored->birthDate()?->toString());
        static::assertSame(['call:020', 'begin', 'save:id-1', 'append:urssaf.tdae.account.synced', 'commit', 'publish:AccountSynced'], $world->journal->entries());
        static::assertSame(['AccountSynced'], $world->publishedEvents());
    }

    public function testTheBirthDateIsOptional(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertFalse($view->hasBirthDate);
        static::assertNull($world->accounts->get(AccountId::fromString('id-1'))->birthDate());
    }

    public function testTheMandateThatPredatesTheAccountIsShown(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-1');
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('mandate-1', $view->mandate?->mandateId);
    }

    public function testATrackedSiretIsReturnedWithoutAnyCallOrWrite(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('account-1', $view->accountId);
        static::assertSame('mandate-1', $view->mandate?->mandateId);
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    public function testTheLatestMandateIsShownWhenNoneIsActive(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->cancelledMandate('mandate-1');
        $world->cancelledMandate('mandate-2');

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('mandate-2', $view->mandate?->mandateId);
    }

    public function testTheSameBirthDateIsNotStoredAgain(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertTrue($view->hasBirthDate);
        static::assertSame([], $world->journal->entries());
    }

    public function testAnotherBirthDateIsStoredOnTheTrackedAccount(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));

        static::assertTrue($view->hasBirthDate);
        static::assertSame('1980-05-17', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertSame([], $world->gateway->calls());
        static::assertSame(['begin', 'save:account-1', 'append:', 'commit'], $world->journal->entries());
    }

    public function testTheEventsOfTheAccountSavedWithTheBirthDateAreRecorded(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $handler = new TrackAccountHandler(new EventfulAccounts($world->accounts), $world->mandates, $world->gateway, $world->ids, $world->clock, $world->unitOfWork);

        $handler(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));

        static::assertSame(['AccountSynced'], $world->publishedEvents());
        static::assertSame(['begin', 'save:account-1', 'append:urssaf.tdae.account.synced', 'commit', 'publish:AccountSynced'], $world->journal->entries());
    }

    public function testAMissingBirthDateIsStoredOnTheTrackedAccount(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertTrue($view->hasBirthDate);
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testAConcurrentModificationWhileStoringTheBirthDatePropagates(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner): void {
            $other = $inner->get(AccountId::fromString('account-1'));
            $other->sync(AccountWorld::status(), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
        });

        try {
            self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));
            static::fail('The account changed meanwhile.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
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
    public function testEveryFailureOfTheCallPropagatesAndStoresNothing(string $case): void
    {
        $world = new AccountWorld();
        $failure = self::failure($case);
        $world->gateway->queue(Operation::FindAccount, $failure);

        try {
            self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));
            static::fail('The failure must propagate.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(['call:020'], $world->journal->entries());
        static::assertNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAConcurrentTrackEndsWithTheWinnersAccount(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());
        $world->gateway->duringNextCall(static function () use ($world): void {
            $world->trackedAccount('account-winner');
        });

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('account-winner', $view->accountId);
        static::assertSame(['call:020', 'begin', 'save:id-1', 'rollback'], $world->journal->entries());
        static::assertSame([], $world->publishedEvents());
        static::assertSame([], $world->outbox->appended());
    }

    public function testADuplicateThatHasNoWinnerIsRethrown(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());
        $duplicate = InvariantViolation::because(AeAccountRepository::DUPLICATE_SIRET, 'Duplicate.');
        $world->beforeNextAccountSave(static function () use ($duplicate): never {
            throw $duplicate;
        });

        try {
            self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('There is no winner to return.');
        } catch (InvariantViolation $violation) {
            static::assertSame($duplicate, $violation);
        }
    }

    public function testAnotherViolationIsRethrownEvenWhenAnAccountAppearedMeanwhile(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());
        $violation = InvariantViolation::because('storage.refused', 'Refused.');
        $winner = AeAccount::track(AccountId::fromString('account-winner'), AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::status(), null, Instant::fromIso8601(AccountWorld::NOW));
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner) use ($winner, $violation): never {
            $inner->save($winner);

            throw $violation;
        });

        try {
            self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('Only a duplicate SIRET is absorbed.');
        } catch (InvariantViolation $thrown) {
            static::assertSame($violation, $thrown);
        }
    }

    public function testTheNewAccountOfAnotherTenantDoesNotCountAsTracked(): void
    {
        $world = new AccountWorld();
        $other = AeAccount::track(AccountId::fromString('account-2'), AccountWorld::tenant('tenant-2'), AccountWorld::siret(), AccountWorld::status(), null, Instant::fromIso8601(AccountWorld::NOW));
        $world->accounts->add($other);
        $world->gateway->queue(Operation::FindAccount, AccountWorld::status());

        $view = self::handler($world)(new TrackAccount(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('id-1', $view->accountId);
        static::assertSame('tenant-1', $view->tenantId);
    }

    private static function handler(AccountWorld $world): TrackAccountHandler
    {
        return new TrackAccountHandler($world->accounts, $world->mandates, $world->gateway, $world->ids, $world->clock, $world->unitOfWork);
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
