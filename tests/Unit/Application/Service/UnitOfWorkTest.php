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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DomainEventSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\Journal;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingTransactionManager;

final class UnitOfWorkTest extends TestCase
{
    public function testPublishesTheRecordedEventsAfterTheCommit(): void
    {
        $journal = new Journal();
        $publisher = new RecordingEventPublisher($journal);
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), $publisher);
        $first = DomainEventSamples::declarationRecorded();
        $second = DomainEventSamples::declarationRequeued();

        $result = $unitOfWork->run(static function (RecordedEvents $events) use ($first, $second, $journal): string {
            $journal->add('work');
            $events->record($first);
            $events->record($second);

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame([$first, $second], $publisher->events());
        static::assertSame(['DeclarationRecorded', 'DeclarationRequeued'], $publisher->names());
        static::assertSame(['begin', 'work', 'commit', 'publish:DeclarationRecorded', 'publish:DeclarationRequeued'], $journal->entries());
    }

    public function testPublishesNothingWhenTheOperationFails(): void
    {
        $journal = new Journal();
        $publisher = new RecordingEventPublisher($journal);
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), $publisher);
        $failure = new RuntimeException('failure');
        $this->expectExceptionObject($failure);

        try {
            $unitOfWork->run(static function (RecordedEvents $events) use ($failure): never {
                $events->record(DomainEventSamples::declarationRecorded());

                throw $failure;
            });
        } finally {
            static::assertSame([], $publisher->events());
            static::assertSame(['begin', 'failed'], $journal->entries());
        }
    }

    public function testReportsAPublicationFailureWithoutFailingTheCommittedOperation(): void
    {
        $journal = new Journal();
        $transactions = new RecordingTransactionManager($journal);
        $publisher = new FailingEventPublisher(new RuntimeException('broker down'));
        $reporter = new RecordingFailureReporter();
        $unitOfWork = new UnitOfWork($transactions, $publisher, $reporter);
        $event = DomainEventSamples::declarationRecorded();

        $result = $unitOfWork->run(static function (RecordedEvents $events) use ($event): string {
            $events->record($event);

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame([[$publisher->failure, [$event]]], $reporter->reports());
        static::assertSame(['begin', 'commit'], $journal->entries());
    }

    public function testIgnoresAPublicationFailureByDefault(): void
    {
        $transactions = new RecordingTransactionManager(new Journal());
        $unitOfWork = new UnitOfWork($transactions, new FailingEventPublisher(new RuntimeException('broker down')));

        static::assertSame('done', $unitOfWork->run(static fn(): string => 'done'));
        static::assertSame(1, $transactions->committed());
    }

    public function testTheDefaultFailureReporterDropsTheFailure(): void
    {
        (new IgnoredFailures())->eventPublicationFailed(new RuntimeException('ignored'), [DomainEventSamples::declarationRecorded()]);

        $journal = new Journal();
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), new RecordingEventPublisher($journal));

        static::assertSame(7, $unitOfWork->run(static fn(): int => 7));
    }
}
