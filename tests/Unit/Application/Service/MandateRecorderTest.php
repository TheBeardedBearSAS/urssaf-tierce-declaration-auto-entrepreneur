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
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\EventfulAccounts;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class MandateRecorderTest extends TestCase
{
    // -- 030 accepted

    public function testANotificationCreatesTheActiveMandate(): void
    {
        $world = new AccountWorld();

        $mandate = $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), null);

        static::assertSame('id-1', $mandate->id()->value());
        static::assertSame('notified', $mandate->origin()->value);
        static::assertTrue($mandate->isActive());
        static::assertNull($mandate->accountId());
        static::assertSame(AccountWorld::SIRET, $mandate->siret()->toString());
        static::assertSame('2026-11-17T09:00:00Z', $mandate->notifiedAt()->toIso8601());
        static::assertSame(['MandateNotified'], $world->publishedEvents());
        static::assertSame(
            ['begin', 'save:id-1', 'append:urssaf.tdae.mandate.notified', 'commit', 'publish:MandateNotified'],
            $world->journal->entries(),
        );
        static::assertInstanceOf(Mandate::class, $world->mandates->findActive(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testTheMandateIsLinkedToTheTrackedAccountAndTheGivenBirthDateIsStored(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();

        $mandate = $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17'));

        static::assertSame('account-1', $mandate->accountId()?->value());
        static::assertSame('1980-05-17', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertSame(['begin', 'save:id-1', 'save:account-1', 'append:urssaf.tdae.mandate.notified', 'commit', 'publish:MandateNotified'], $world->journal->entries());
    }

    public function testWithoutAGivenBirthDateTheAccountIsNotWritten(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), null);

        static::assertNotContains('save:account-1', $world->journal->entries());
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testTheEventsOfTheAccountSavedWithTheBirthDateAreRecorded(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $recorder = new MandateRecorder($world->mandates, new EventfulAccounts($world->accounts), $world->ids, $world->clock, $world->unitOfWork);

        $recorder->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());

        static::assertSame(['MandateNotified', 'AccountSynced'], $world->publishedEvents());
        static::assertSame(
            ['begin', 'save:id-1', 'save:account-1', 'append:urssaf.tdae.mandate.notified,urssaf.tdae.account.synced', 'commit', 'publish:MandateNotified', 'publish:AccountSynced'],
            $world->journal->entries(),
        );
    }

    public function testAGivenBirthDateIsNeverStoredWithoutATrackedAccount(): void
    {
        $world = new AccountWorld();

        $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());

        static::assertNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame(['begin', 'save:id-1', 'append:urssaf.tdae.mandate.notified', 'commit', 'publish:MandateNotified'], $world->journal->entries());
    }

    public function testAnActiveMandateAnotherWriterAlreadyRecordedIsKept(): void
    {
        $world = new AccountWorld();
        $existing = $world->activeMandate('mandate-existing');

        $mandate = $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), null);

        static::assertSame($existing->id()->value(), $mandate->id()->value());
        static::assertSame(['begin', 'append:', 'commit'], $world->journal->entries());
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAMandateKeptFromAnotherWriterStillStoresTheBirthDate(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $world->activeMandate();

        $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());

        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testAConflictOfAnotherMandateIsRetriedAndTheMandateTheOtherWriterCreatedIsKept(): void
    {
        $world = new AccountWorld();
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner): void {
            $inner->save(self::mandate('mandate-other'));
        });

        $mandate = $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), null);

        static::assertSame('mandate-other', $mandate->id()->value());
        static::assertSame(['begin', 'save:id-1', 'rollback', 'begin', 'append:', 'commit'], $world->journal->entries());
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAConflictOfTheAccountIsRetriedWithTheAccountReadAgain(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner): void {
            self::touchAccount($inner);
        });

        $mandate = $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());

        static::assertTrue($mandate->isActive());
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertSame(
            ['begin', 'save:id-1', 'save:account-1', 'rollback', 'begin', 'save:id-2', 'save:account-1', 'append:urssaf.tdae.mandate.notified', 'commit', 'publish:MandateNotified'],
            $world->journal->entries(),
        );
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testEveryAttemptConflictingFailsWithTheLastCode(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        self::conflictEveryTime($world);

        try {
            $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());
            static::fail('Every attempt conflicts.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame('mandate.recording_failed', $failure->violationCode());
            static::assertSame(['operation' => '030', 'attempts' => 3, 'last_code' => AeAccountRepository::CONCURRENT_MODIFICATION], $failure->params());
        }

        static::assertCount(3, array_keys($world->journal->entries(), 'rollback', true));
        static::assertSame([], $world->publishedEvents());
        static::assertSame([], $world->outbox->appended());
    }

    public function testTheFailedAttemptsAreRolledBackNothingIsLeftBehind(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        self::conflictEveryTime($world);

        try {
            $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());
        } catch (MandateRecordingFailed $failure) {
            static::assertSame('mandate.recording_failed', $failure->violationCode());
        }

        static::assertNotInstanceOf(Mandate::class, $world->mandates->findActive(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertNull($world->accounts->get(AccountId::fromString('account-1'))->birthDate());
    }

    public function testWithoutARollbackTheNextAttemptCompletesWhatTheFailedOneSaved(): void
    {
        $world = new AccountWorld(false);
        $world->trackedAccountWithoutBirthDate();
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner): void {
            self::touchAccount($inner);
        });

        $mandate = $world->recorder()->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());

        static::assertSame('id-1', $mandate->id()->value());
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testAFailureThatIsNotAConflictFailsOnTheFirstAttemptWithItsViolationCode(): void
    {
        $world = new AccountWorld();
        $recorder = $world->recorderWithFailingOutbox(InvariantViolation::because('mandate.storage_refused', 'Refused.'));

        try {
            $recorder->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), null);
            static::fail('The recording must fail.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '030', 'attempts' => 1, 'last_code' => 'mandate.storage_refused'], $failure->params());
        }
        static::assertSame(['begin', 'save:id-1', 'rollback'], $world->journal->entries());
    }

    public function testAForeignFailureIsReportedWithItsClassName(): void
    {
        $world = new AccountWorld();
        $recorder = $world->recorderWithFailingOutbox(new RuntimeException('storage down'));

        try {
            $recorder->recordNotification(AccountWorld::tenant(), AccountWorld::siret(), null);
            static::fail('The recording must fail.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '030', 'attempts' => 1, 'last_code' => RuntimeException::class], $failure->params());
            static::assertStringNotContainsString('storage down', $failure->getMessage());
        }
    }

    // -- 031 accepted

    public function testACancellationCancelsTheActiveMandate(): void
    {
        $world = new AccountWorld();
        $mandate = $world->activeMandate();

        $cancelled = $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), $mandate->id(), null);

        static::assertFalse($cancelled->isActive());
        static::assertSame('cancelled', $cancelled->cancellationOrigin()?->value);
        static::assertSame('2026-11-17T09:00:00Z', $cancelled->cancelledAt()?->toIso8601());
        static::assertSame(['MandateCancelled'], $world->publishedEvents());
        static::assertSame(['begin', 'save:mandate-1', 'append:urssaf.tdae.mandate.cancelled', 'commit', 'publish:MandateCancelled'], $world->journal->entries());
    }

    public function testACancellationStoresTheGivenBirthDateOnTheTrackedAccount(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $mandate = $world->activeMandate();

        $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), $mandate->id(), AccountWorld::birthDate());

        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertSame(['begin', 'save:mandate-1', 'save:account-1', 'append:urssaf.tdae.mandate.cancelled', 'commit', 'publish:MandateCancelled'], $world->journal->entries());
    }

    public function testACancellationWithoutAGivenBirthDateLeavesTheAccountAlone(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $mandate = $world->activeMandate();

        $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), $mandate->id(), null);

        static::assertNotContains('save:account-1', $world->journal->entries());
    }

    public function testAMandateAnotherWriterAlreadyCancelledIsKept(): void
    {
        $world = new AccountWorld();
        $mandate = $world->cancelledMandate();

        $kept = $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), $mandate->id(), null);

        static::assertSame('2026-11-10T08:00:00Z', $kept->cancelledAt()?->toIso8601());
        static::assertSame('imported', $kept->origin()->value);
        static::assertSame(['begin', 'append:', 'commit'], $world->journal->entries());
    }

    public function testAMandateErasedBeforeTheCancellationIsRecordedFailsWithItsNotFoundCode(): void
    {
        $world = new AccountWorld();

        try {
            $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), MandateId::fromString('mandate-9'), null);
            static::fail('The mandate is gone.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '031', 'attempts' => 1, 'last_code' => 'mandate.not_found'], $failure->params());
        }
    }

    public function testAConflictOnTheMandateIsRetriedAndTheCancellationAnotherWriterRecordedIsKept(): void
    {
        $world = new AccountWorld();
        $mandate = $world->activeMandate();
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner) use ($mandate): void {
            $other = $inner->get($mandate->id());
            $other->recordCancellation(Instant::fromIso8601('2026-11-16T08:00:00Z'));
            $inner->save($other);
        });

        $kept = $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), $mandate->id(), null);

        static::assertSame('recorded', $kept->cancellationOrigin()?->value);
        static::assertSame(['begin', 'save:mandate-1', 'rollback', 'begin', 'append:', 'commit'], $world->journal->entries());
    }

    public function testEveryAttemptConflictingOnTheCancellationFailsWithTheLastCode(): void
    {
        $world = new AccountWorld();
        $mandate = $world->activeMandate();
        self::conflictOnTheMandateEveryTime($world, $mandate->id());

        try {
            $world->recorder()->recordCancellation(AccountWorld::tenant(), AccountWorld::siret(), $mandate->id(), null);
            static::fail('Every attempt conflicts.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '031', 'attempts' => 3, 'last_code' => MandateRepository::CONCURRENT_MODIFICATION], $failure->params());
        }
    }

    // -- recorded without any call

    public function testAnImportCreatesAnImportedMandate(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();

        $mandate = $world->recorder()->recordImport(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17'));

        static::assertSame('imported', $mandate->origin()->value);
        static::assertSame('account-1', $mandate->accountId()?->value());
        static::assertSame('1980-05-17', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnImportKeepsTheActiveMandate(): void
    {
        $world = new AccountWorld();
        $existing = $world->activeMandate();

        $mandate = $world->recorder()->recordImport(AccountWorld::tenant(), AccountWorld::siret(), null);

        static::assertSame($existing->id()->value(), $mandate->id()->value());
        static::assertSame('notified', $mandate->origin()->value);
    }

    public function testAnImportRethrowsTheLastConflictAfterEveryAttempt(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        self::conflictEveryTime($world);

        try {
            $world->recorder()->recordImport(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate());
            static::fail('Every attempt conflicts.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertCount(3, array_keys($world->journal->entries(), 'rollback', true));
    }

    public function testAnImportRethrowsAFailureThatIsNotAConflictAsItIs(): void
    {
        $world = new AccountWorld();
        $failure = new RuntimeException('storage down');
        $recorder = $world->recorderWithFailingOutbox($failure);

        try {
            $recorder->recordImport(AccountWorld::tenant(), AccountWorld::siret(), null);
            static::fail('The failure must propagate.');
        } catch (RuntimeException $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(['begin', 'save:id-1', 'rollback'], $world->journal->entries());
    }

    public function testAnObservedCancellationIsRecordedWithTheOriginRecorded(): void
    {
        $world = new AccountWorld();
        $mandate = $world->activeMandate();

        $cancelled = $world->recorder()->recordObservedCancellation($mandate->id());

        static::assertFalse($cancelled->isActive());
        static::assertSame('recorded', $cancelled->cancellationOrigin()?->value);
        static::assertSame(['begin', 'save:mandate-1', 'append:urssaf.tdae.mandate.cancelled', 'commit', 'publish:MandateCancelled'], $world->journal->entries());
    }

    public function testAnObservedCancellationOfACancelledMandateIsIdempotent(): void
    {
        $world = new AccountWorld();
        $mandate = $world->cancelledMandate();

        $kept = $world->recorder()->recordObservedCancellation($mandate->id());

        static::assertSame('cancelled', $kept->cancellationOrigin()?->value);
        static::assertSame(['begin', 'append:', 'commit'], $world->journal->entries());
    }

    public function testAnObservedCancellationOfAnErasedMandateIsRethrownAsItIs(): void
    {
        $world = new AccountWorld();

        try {
            $world->recorder()->recordObservedCancellation(MandateId::fromString('mandate-9'));
            static::fail('The mandate is gone.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('mandate.not_found', $notFound->violationCode());
        }
    }

    public function testAnObservedCancellationRetriesAConflictThenRethrowsTheLastOne(): void
    {
        $world = new AccountWorld();
        $mandate = $world->activeMandate();
        self::conflictOnTheMandateEveryTime($world, $mandate->id());

        try {
            $world->recorder()->recordObservedCancellation($mandate->id());
            static::fail('Every attempt conflicts.');
        } catch (InvariantViolation $violation) {
            static::assertSame(MandateRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertCount(3, array_keys($world->journal->entries(), 'rollback', true));
    }

    public function testTheOperationIsTheOneOfTheCall(): void
    {
        static::assertSame('030', Operation::NotifyMandate->value);
        static::assertSame('031', Operation::CancelMandate->value);
    }

    private static function accountId(): AccountId
    {
        return AccountId::fromString('account-1');
    }

    private static function mandate(string $id): Mandate
    {
        $mandate = Mandate::import(MandateId::fromString($id), AccountWorld::tenant(), AccountWorld::siret(), null, Instant::fromIso8601('2026-11-16T08:00:00Z'));
        $mandate->releaseEvents();

        return $mandate;
    }

    /**
     * Another writer changes the account of the SIRET: its version moves on.
     */
    private static function touchAccount(InMemoryAeAccountRepository $inner): void
    {
        $other = $inner->get(self::accountId());
        $other->sync(AccountWorld::status(), Instant::fromIso8601('2026-11-17T08:00:00Z'));
        $inner->save($other);
    }

    /**
     * Arms the account store so that every save of the unit of work meets another writer, whatever the number of attempts.
     */
    private static function conflictEveryTime(AccountWorld $world): void
    {
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner) use ($world): void {
            self::touchAccount($inner);
            self::conflictEveryTime($world);
        });
    }

    private static function conflictOnTheMandateEveryTime(AccountWorld $world, MandateId $id): void
    {
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner) use ($world, $id): void {
            $inner->save($inner->get($id));
            self::conflictOnTheMandateEveryTime($world, $id);
        });
    }
}
