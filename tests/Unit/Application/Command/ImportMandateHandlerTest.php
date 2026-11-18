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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class ImportMandateHandlerTest extends TestCase
{
    public function testAnImportedMandateIsRecordedWithoutAnyCall(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('id-1', $view->mandateId);
        static::assertSame('imported', $view->origin);
        static::assertSame('notified', $view->state);
        static::assertSame('account-1', $view->accountId);
        static::assertSame([], $world->gateway->calls());
        static::assertSame(['begin', 'save:id-1', 'append:urssaf.tdae.mandate.notified', 'commit', 'publish:MandateNotified'], $world->journal->entries());
    }

    public function testTheBirthDateIsStoredOnTheTrackedAccount(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();

        self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testAMandateMayBeImportedBeforeTheAccountIsTracked(): void
    {
        $world = new AccountWorld();

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertNull($view->accountId);
        static::assertNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAnActiveMandateIsReturnedWithoutAnyWrite(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate('mandate-existing');

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('mandate-existing', $view->mandateId);
        static::assertSame('notified', $view->origin);
        static::assertSame([], $world->journal->entries());
    }

    public function testAGivenBirthDateWithoutATrackedAccountLeavesTheActiveMandateUntouched(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-existing');

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('mandate-existing', $view->mandateId);
        static::assertSame([], $world->journal->entries());
    }

    public function testAGivenBirthDateIsStoredEvenWhenTheMandateExists(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $world->activeMandate('mandate-existing');

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('mandate-existing', $view->mandateId);
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertSame(['begin', 'save:account-1', 'append:', 'commit'], $world->journal->entries());
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testACancelledMandateDoesNotPreventTheImport(): void
    {
        $world = new AccountWorld();
        $world->cancelledMandate('mandate-old');

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('id-1', $view->mandateId);
        static::assertCount(2, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAConflictIsRetried(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner): void {
            $other = $inner->get(AccountId::fromString('account-1'));
            $other->sync(AccountWorld::status(), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
        });

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('imported', $view->origin);
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAMandateCreatedByAnotherWriterWhileRecordingIsKept(): void
    {
        $world = new AccountWorld();
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner): void {
            $inner->save(Mandate::notify(MandateId::fromString('mandate-other'), AccountWorld::tenant(), AccountWorld::siret(), null, Instant::fromIso8601('2026-11-16T08:00:00Z')));
        });

        $view = self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('mandate-other', $view->mandateId);
    }

    public function testTheLastConflictIsRethrownWhenEveryAttemptConflicts(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        self::conflictEveryTime($world);

        try {
            self::handler($world)(new ImportMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));
            static::fail('Every attempt conflicts.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertNotInstanceOf(Mandate::class, $world->mandates->findActive(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame([], $world->gateway->calls());
    }

    private static function conflictEveryTime(AccountWorld $world): void
    {
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner) use ($world): void {
            $other = $inner->get(AccountId::fromString('account-1'));
            $other->sync(AccountWorld::status(), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
            self::conflictEveryTime($world);
        });
    }

    private static function handler(AccountWorld $world): ImportMandateHandler
    {
        return new ImportMandateHandler($world->accounts, $world->mandates, $world->recorder());
    }
}
