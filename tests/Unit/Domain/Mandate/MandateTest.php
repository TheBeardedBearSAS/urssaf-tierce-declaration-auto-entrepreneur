<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Mandate;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateCancellationOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

final class MandateTest extends TestCase
{
    public function testNotify(): void
    {
        $mandate = Mandate::notify(
            MandateSamples::id(),
            MandateSamples::tenantId(),
            MandateSamples::siret(),
            MandateSamples::accountId(),
            MandateSamples::notifiedAt(),
        );

        static::assertSame('mandate-1', $mandate->id()->value());
        static::assertSame('tenant-1', $mandate->tenantId()->value());
        static::assertSame('12345678901234', $mandate->siret()->toString());
        static::assertSame('account-1', $mandate->accountId()?->value());
        static::assertSame(MandateOrigin::Notified, $mandate->origin());
        static::assertSame(MandateState::Notified, $mandate->state());
        static::assertTrue($mandate->isActive());
        static::assertNull($mandate->cancellationOrigin());
        static::assertSame('2026-11-15T10:00:00Z', $mandate->notifiedAt()->toIso8601());
        static::assertNull($mandate->cancelledAt());
        static::assertSame('2026-11-15T10:00:00Z', $mandate->updatedAt()->toIso8601());
        static::assertSame('2026-11-15T10:00:00Z', $mandate->timeline()->notifiedAt()->toIso8601());

        $events = $mandate->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(MandateNotified::class, $event);
        static::assertSame('mandate-1', $event->mandateId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame(MandateOrigin::Notified, $event->origin());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
        static::assertSame([], $mandate->releaseEvents());
    }

    public function testImport(): void
    {
        $mandate = Mandate::import(
            MandateSamples::id(),
            MandateSamples::tenantId(),
            MandateSamples::siret(),
            null,
            MandateSamples::notifiedAt(),
        );

        static::assertSame(MandateOrigin::Imported, $mandate->origin());
        static::assertSame(MandateState::Notified, $mandate->state());
        static::assertNull($mandate->accountId());
        $events = $mandate->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(MandateNotified::class, $events[0]);
        static::assertSame(MandateOrigin::Imported, $events[0]->origin());
        static::assertNull($events[0]->accountId());
    }

    public function testCancel(): void
    {
        $mandate = MandateSamples::notified();
        $now = MandateSamples::later();

        $mandate->cancel($now);

        static::assertSame(MandateState::Cancelled, $mandate->state());
        static::assertFalse($mandate->isActive());
        static::assertSame($now, $mandate->cancelledAt());
        static::assertSame($now, $mandate->updatedAt());
        static::assertSame(MandateCancellationOrigin::Cancelled, $mandate->cancellationOrigin());
        static::assertSame('2026-11-15T10:00:00Z', $mandate->notifiedAt()->toIso8601());
        $events = $mandate->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(MandateCancelled::class, $event);
        static::assertSame('mandate-1', $event->mandateId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame(MandateCancellationOrigin::Cancelled, $event->origin());
        static::assertSame('2026-11-16T11:30:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testRecordCancellation(): void
    {
        $mandate = Mandate::import(MandateSamples::id(), MandateSamples::tenantId(), MandateSamples::siret(), null, MandateSamples::notifiedAt());
        $mandate->releaseEvents();
        $now = MandateSamples::later();

        $mandate->recordCancellation($now);

        static::assertSame(MandateState::Cancelled, $mandate->state());
        static::assertSame(MandateCancellationOrigin::Recorded, $mandate->cancellationOrigin());
        static::assertSame($now, $mandate->cancelledAt());
        static::assertSame($now, $mandate->updatedAt());
        $events = $mandate->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(MandateCancelled::class, $events[0]);
        static::assertSame(MandateCancellationOrigin::Recorded, $events[0]->origin());
        static::assertNull($events[0]->accountId());
    }

    public function testCancelIsRefusedWhenAlreadyCancelled(): void
    {
        $mandate = MandateSamples::inState(MandateState::Cancelled);
        $cancelledAt = $mandate->cancelledAt();

        try {
            $mandate->cancel(MandateSamples::later());
            static::fail('Expected an InvalidStateTransition.');
        } catch (InvalidStateTransition $exception) {
            static::assertSame('mandate.invalid_state_transition', $exception->violationCode());
            static::assertSame('cancelled', $exception->from());
            static::assertSame('cancelled', $exception->to());
        }

        static::assertSame($cancelledAt, $mandate->cancelledAt());
        static::assertSame(MandateCancellationOrigin::Cancelled, $mandate->cancellationOrigin());
        static::assertSame([], $mandate->releaseEvents());
    }

    public function testRecordedCancellationIsRefusedWhenAlreadyCancelledAndKeepsItsOrigin(): void
    {
        $mandate = MandateSamples::inState(MandateState::Cancelled);

        try {
            $mandate->recordCancellation(MandateSamples::later());
            static::fail('Expected an InvalidStateTransition.');
        } catch (InvalidStateTransition) {
            static::assertSame(MandateCancellationOrigin::Cancelled, $mandate->cancellationOrigin());
        }

        static::assertSame([], $mandate->releaseEvents());
    }

    public function testEventsAreReleasedInOrder(): void
    {
        $mandate = Mandate::notify(MandateSamples::id(), MandateSamples::tenantId(), MandateSamples::siret(), MandateSamples::accountId(), MandateSamples::notifiedAt());
        $mandate->cancel(MandateSamples::later());

        static::assertSame(
            [MandateNotified::class, MandateCancelled::class],
            array_map(static fn(DomainEvent $event): string => $event::class, $mandate->releaseEvents()),
        );
    }

    public function testNoEventCarriesTheSiret(): void
    {
        $mandate = Mandate::notify(MandateSamples::id(), MandateSamples::tenantId(), MandateSamples::siret(), MandateSamples::accountId(), MandateSamples::notifiedAt());
        $mandate->cancel(MandateSamples::later());

        foreach ($mandate->releaseEvents() as $event) {
            $dump = print_r($event, true);
            static::assertStringNotContainsString('12345678901234', $dump, $event::class);
            static::assertStringNotContainsString('1975', $dump, $event::class);
        }
    }
}
