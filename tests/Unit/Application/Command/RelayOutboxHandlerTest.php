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

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;

/**
 * One method per scenario.
 *
 * @mago-expect lint:too-many-methods
 */
final class RelayOutboxHandlerTest extends TestCase
{
    public function testThePendingEventsAreDeliveredInOrderThenMarkedAsDispatched(): void
    {
        $outbox = self::outbox(self::withAggregate('e1', 'mandate-1'), self::withAggregate('e2', 'mandate-2'), self::withoutAggregate('e3'));
        $sink = new RecordingIntegrationEventSink();

        $report = self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame([3, 0, 0], [$report->dispatched, $report->failed, $report->deferred]);
        static::assertSame([], $outbox->pending(10));
        static::assertSame(['e1', 'e2', 'e3'], $sink->deliveredIds());
    }

    public function testAFailedEventStaysPendingAndHoldsBackTheNextEventsOfItsAggregateOnly(): void
    {
        $outbox = self::outbox(
            self::withAggregate('e1', 'mandate-1'),
            self::withAggregate('e2', 'mandate-1'),
            self::withAggregate('e3', 'mandate-2'),
            self::withAggregate('e4', 'mandate-1'),
        );
        $sink = new RecordingIntegrationEventSink(['e1']);

        $report = self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame([1, 1, 2], [$report->dispatched, $report->failed, $report->deferred]);
        $pending = $outbox->pending(10);
        static::assertSame([1, 2, 4], array_map(static fn(OutboxEntry $entry): int => $entry->sequence, $pending));
        static::assertSame([1, 0, 0], array_map(static fn(OutboxEntry $entry): int => $entry->attempts, $pending));
        static::assertSame(['e3'], $sink->deliveredIds());
    }

    public function testAFailedEventWithoutAggregateHoldsBackTheNextOnesOfItsTenantOnly(): void
    {
        $outbox = self::outbox(
            self::withoutAggregate('e1', 'tenant-a'),
            self::withoutAggregate('e2', 'tenant-a'),
            self::withoutAggregate('e3', 'tenant-b'),
            self::withAggregate('e4', 'mandate-1', 'tenant-a'),
        );
        $sink = new RecordingIntegrationEventSink(['e1']);

        $report = self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame([2, 1, 1], [$report->dispatched, $report->failed, $report->deferred]);
        static::assertSame(['e3', 'e4'], $sink->deliveredIds());
        static::assertSame(['e1', 'e2'], array_map(static fn(OutboxEntry $entry): string => $entry->event->eventId, $outbox->pending(10)));
    }

    public function testAFailedEventWithAnAggregateHoldsBackNeitherEventsWithoutAggregateNorOtherAggregates(): void
    {
        $outbox = self::outbox(
            self::withAggregate('e1', 'mandate-1', 'tenant-a'),
            self::withoutAggregate('e2', 'tenant-a'),
            self::withAggregate('e3', 'mandate-2', 'tenant-a'),
            self::withAggregate('e4', 'mandate-1', 'tenant-a'),
        );
        $sink = new RecordingIntegrationEventSink(['e1']);

        $report = self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame([2, 1, 1], [$report->dispatched, $report->failed, $report->deferred]);
        static::assertSame(['e2', 'e3'], $sink->deliveredIds());
    }

    public function testAFailedAggregateEventDoesNotHoldBackTheEventsWithoutAggregateOfATenantNamedLikeIt(): void
    {
        $outbox = self::outbox(
            self::withAggregate('e1', 'same', 'tenant-a'),
            self::withoutAggregate('e2', 'same'),
        );
        $sink = new RecordingIntegrationEventSink(['e1']);

        $report = self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame([1, 1, 0], [$report->dispatched, $report->failed, $report->deferred]);
        static::assertSame(['e2'], $sink->deliveredIds());
    }

    public function testAFailedEventWithoutAggregateDoesNotHoldBackTheEventsOfAnAggregateNamedLikeItsTenant(): void
    {
        $outbox = self::outbox(
            self::withoutAggregate('e1', 'same'),
            self::withAggregate('e2', 'same', 'tenant-a'),
        );
        $sink = new RecordingIntegrationEventSink(['e1']);

        $report = self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame([1, 1, 0], [$report->dispatched, $report->failed, $report->deferred]);
        static::assertSame(['e2'], $sink->deliveredIds());
    }

    public function testTheFailureIsStoredWithTheClassAndTheCodeOnly(): void
    {
        $outbox = self::outbox(self::withAggregate('e1', 'mandate-1'));
        $sink = new class implements IntegrationEventSink {
            public function dispatch(IntegrationEvent $event): void
            {
                throw new LogicException('SIRET 73282932000075 for ' . $event->eventId, 42);
            }
        };

        self::handler($outbox, $sink)(new RelayOutbox(null));

        static::assertSame('LogicException (code 42)', $outbox->lastError(1));
    }

    public function testTheMessageOfTheSinkIsNeverStored(): void
    {
        $outbox = self::outbox(self::withAggregate('e1', 'mandate-1'));

        self::handler($outbox, new RecordingIntegrationEventSink(['e1']))(new RelayOutbox(null));

        static::assertSame('RuntimeException (code 7)', $outbox->lastError(1));
    }

    public function testTheLimitIsCappedByThePolicy(): void
    {
        $outbox = self::outbox(self::withAggregate('e1', 'mandate-1'), self::withAggregate('e2', 'mandate-2'), self::withAggregate('e3', 'mandate-3'));
        $handler = new RelayOutboxHandler(
            $outbox,
            new RecordingIntegrationEventSink(),
            self::clock(),
            (new WorkflowPolicy())->withPageSizes(1, 2),
        );

        static::assertSame(1, $handler(new RelayOutbox(null))->dispatched);
        static::assertSame(2, $handler(new RelayOutbox(10))->dispatched);
        static::assertSame([], $outbox->pending(10));
    }

    public function testTheDispatchInstantIsTheCurrentInstant(): void
    {
        $outbox = self::outbox(self::withAggregate('e1', 'mandate-1'), self::withoutAggregate('e2'));

        self::handler($outbox, new RecordingIntegrationEventSink())(new RelayOutbox(null));

        static::assertSame(0, $outbox->purgeDispatchedBefore(Instant::fromIso8601('2026-11-17T09:00:00Z')));
        static::assertSame(2, $outbox->purgeDispatchedBefore(Instant::fromIso8601('2026-11-17T09:00:01Z')));
    }

    private static function withAggregate(string $eventId, string $aggregateId, string $tenantId = 'tenant-1'): MandateNotified
    {
        return new MandateNotified($eventId, '2026-11-15T10:00:00Z', $tenantId, $aggregateId, null, null, 'notified');
    }

    private static function withoutAggregate(string $eventId, string $tenantId = 'tenant-1'): SepaMandateRegistered
    {
        return new SepaMandateRegistered($eventId, '2026-11-15T10:00:00Z', $tenantId, null, null, 'RUM-1');
    }

    private static function outbox(IntegrationEvent ...$events): InMemoryOutbox
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(...$events);

        return $outbox;
    }

    private static function handler(InMemoryOutbox $outbox, IntegrationEventSink $sink): RelayOutboxHandler
    {
        return new RelayOutboxHandler($outbox, $sink, self::clock(), new WorkflowPolicy());
    }

    private static function clock(): FixedClock
    {
        return new FixedClock('2026-11-17T09:00:00Z');
    }
}
