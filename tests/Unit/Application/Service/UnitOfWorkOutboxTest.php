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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DomainEventSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\Journal;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\JournalingOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingTransactionManager;

final class UnitOfWorkOutboxTest extends TestCase
{
    public function testTheIntegrationEventsAreAppendedInsideTheTransactionAfterTheOperationAndBeforeThePublication(): void
    {
        $journal = new Journal();
        $outbox = new JournalingOutbox($journal);
        $unitOfWork = new UnitOfWork(
            new RecordingTransactionManager($journal),
            new RecordingEventPublisher($journal),
            new IgnoredFailures(),
            $outbox,
        );

        $result = $unitOfWork->run(static function (RecordedEvents $events) use ($journal): string {
            $journal->add('work');
            $events->record(DomainEventSamples::declarationDeclared(), DomainEventSamples::declarationRequeued(), DomainEventSamples::mandateNotified());

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame(
            [
                'begin',
                'work',
                'append:urssaf.tdae.declaration.declared,urssaf.tdae.mandate.notified',
                'commit',
                'publish:DeclarationDeclared',
                'publish:DeclarationRequeued',
                'publish:MandateNotified',
            ],
            $journal->entries(),
        );
        $declared = $outbox->appended()[0] ?? null;
        static::assertInstanceOf(DeclarationDeclared::class, $declared);
        static::assertSame('account-1', $declared->accountId);
    }

    public function testEveryFamilyIsTranslatedWithoutAnyLookup(): void
    {
        $journal = new Journal();
        $outbox = new JournalingOutbox($journal);
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), new RecordingEventPublisher($journal), new IgnoredFailures(), $outbox);

        $unitOfWork->run(static function (RecordedEvents $events): void {
            $events->record(
                DomainEventSamples::accountSynced(),
                DomainEventSamples::mandateNotified(),
                DomainEventSamples::mandateCancelled(),
                DomainEventSamples::paymentOrdered(),
                DomainEventSamples::accountForgotten(),
            );
        });

        static::assertSame(
            [
                'begin',
                'append:urssaf.tdae.account.synced,urssaf.tdae.mandate.notified,urssaf.tdae.mandate.cancelled,urssaf.tdae.payment.ordered,urssaf.tdae.account.forgotten',
                'commit',
                'publish:AccountSynced',
                'publish:MandateNotified',
                'publish:MandateCancelled',
                'publish:PaymentOrdered',
                'publish:AccountForgotten',
            ],
            $journal->entries(),
        );
    }

    public function testNothingIsAppendedWhenTheOperationFails(): void
    {
        $journal = new Journal();
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), new RecordingEventPublisher($journal), new IgnoredFailures(), new JournalingOutbox($journal));

        $this->expectException(RuntimeException::class);

        try {
            $unitOfWork->run(static function (RecordedEvents $events): never {
                $events->record(DomainEventSamples::declarationDeclared());

                throw new RuntimeException('failure');
            });
        } finally {
            static::assertSame(['begin', 'failed'], $journal->entries());
        }
    }

    public function testAnOperationWithoutIntegrationEventsAppendsAnEmptyBatch(): void
    {
        $journal = new Journal();
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), new RecordingEventPublisher($journal), new IgnoredFailures(), new JournalingOutbox($journal));

        $unitOfWork->run(static function (RecordedEvents $events): void {
            $events->record(DomainEventSamples::declarationRecorded());
        });

        static::assertSame(['begin', 'append:', 'commit', 'publish:DeclarationRecorded'], $journal->entries());
    }

    public function testAFailingOutboxFailsTheTransactionAndPublishesNothing(): void
    {
        $journal = new Journal();
        $publisher = new RecordingEventPublisher($journal);
        $failure = new RuntimeException('database gone');
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), $publisher, new IgnoredFailures(), new FailingOutbox($failure));

        $this->expectExceptionObject($failure);

        try {
            $unitOfWork->run(static function (RecordedEvents $events): void {
                $events->record(DomainEventSamples::declarationDeclared());
            });
        } finally {
            static::assertSame(['begin', 'failed'], $journal->entries());
            static::assertSame([], $publisher->events());
        }
    }

    public function testThePublicationFailureIsReportedAfterTheOutboxAppend(): void
    {
        $journal = new Journal();
        $reporter = new RecordingFailureReporter();
        $outbox = new JournalingOutbox($journal);
        $unitOfWork = new UnitOfWork(
            new RecordingTransactionManager($journal),
            new FailingEventPublisher(new RuntimeException('broker down')),
            $reporter,
            $outbox,
        );

        $unitOfWork->run(static function (RecordedEvents $events): void {
            $events->record(DomainEventSamples::mandateCancelled());
        });

        static::assertSame(['begin', 'append:urssaf.tdae.mandate.cancelled', 'commit'], $journal->entries());
        static::assertCount(1, $reporter->reports());
        static::assertCount(1, $outbox->appended());
    }

    public function testTheDefaultOutboxRecordsNothing(): void
    {
        $journal = new Journal();
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), new RecordingEventPublisher($journal));

        $result = $unitOfWork->run(static function (RecordedEvents $events): string {
            $events->record(DomainEventSamples::declarationDeclared());

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame(['begin', 'commit', 'publish:DeclarationDeclared'], $journal->entries());
    }
}
