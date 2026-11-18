<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Behaviour shared by every Outbox adapter (ADR 0014). The samples are events of the catalogue: one with an aggregate
 * and no reference, one with both, one with neither (SEPA), which proves that the adapter stores the nullable common
 * properties.
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 */
trait OutboxContract
{
    abstract protected function createOutbox(): Outbox;

    public function testTheAppendedEventsArePendingInRecordingOrder(): void
    {
        $outbox = $this->createOutbox();
        $outbox->append(self::notified('e1'), self::rejected('e2'));
        $outbox->append(self::sepa('e3'));

        $pending = $outbox->pending(10);

        static::assertSame(['e1', 'e2', 'e3'], self::ids($pending));
        static::assertEquals(self::notified('e1'), $pending[0]->event);
        static::assertEquals(self::rejected('e2'), $pending[1]->event);
        static::assertEquals(self::sepa('e3'), $pending[2]->event);
        static::assertSame([0, 0, 0], array_map(static fn(OutboxEntry $entry): int => $entry->attempts, $pending));
        static::assertLessThan($pending[1]->sequence, $pending[0]->sequence);
        static::assertLessThan($pending[2]->sequence, $pending[1]->sequence);
        static::assertSame(['e1', 'e2'], self::ids($outbox->pending(2)));
    }

    public function testTheNullableCommonPropertiesAreStoredAsNull(): void
    {
        $outbox = $this->createOutbox();
        $outbox->append(self::notified('e1'), self::rejected('e2'), self::sepa('e3'));

        [$notified, $rejected, $sepa] = array_map(static fn(OutboxEntry $entry): IntegrationEvent => $entry->event, $outbox->pending(10));

        static::assertSame('mandate-1', $notified->aggregateId);
        static::assertNull($notified->externalReference);
        static::assertSame('payment-1', $rejected->aggregateId);
        static::assertSame('payment-ref-1', $rejected->externalReference);
        static::assertNull($sepa->aggregateId);
        static::assertNull($sepa->externalReference);
    }

    public function testADispatchedEntryIsNoLongerPending(): void
    {
        $outbox = $this->createOutbox();
        $outbox->append(self::notified('e1'), self::notified('e2'));

        $outbox->markDispatched($outbox->pending(1)[0], Instant::fromIso8601('2026-11-15T10:00:00Z'));

        static::assertSame(['e2'], self::ids($outbox->pending(10)));
    }

    public function testAFailedEntryStaysPendingWithOneMoreAttempt(): void
    {
        $outbox = $this->createOutbox();
        $outbox->append(self::notified('e1'));

        $outbox->markFailed($outbox->pending(1)[0], 'RuntimeException (code 7)');
        $outbox->markFailed($outbox->pending(1)[0], 'RuntimeException (code 8)');

        $pending = $outbox->pending(10);
        static::assertSame(['e1'], self::ids($pending));
        static::assertSame(2, $pending[0]->attempts);
    }

    public function testThePurgeDeletesTheEntriesDispatchedBeforeTheInstantOnly(): void
    {
        $outbox = $this->createOutbox();
        $outbox->append(self::notified('e1'), self::notified('e2'), self::notified('e3'));
        [$first, $second] = $outbox->pending(2);
        $outbox->markDispatched($first, Instant::fromIso8601('2026-11-15T10:00:00Z'));
        $outbox->markDispatched($second, Instant::fromIso8601('2026-11-16T10:00:00Z'));

        static::assertSame(0, $outbox->purgeDispatchedBefore(Instant::fromIso8601('2026-11-15T10:00:00Z')));
        static::assertSame(1, $outbox->purgeDispatchedBefore(Instant::fromIso8601('2026-11-16T10:00:00Z')));
        static::assertSame(1, $outbox->purgeDispatchedBefore(Instant::fromIso8601('2026-11-17T10:00:00Z')));
        static::assertSame(['e3'], self::ids($outbox->pending(10)));
    }

    private static function notified(string $eventId): MandateNotified
    {
        return new MandateNotified($eventId, '2026-11-15T10:00:00Z', 'tenant-1', 'mandate-1', null, 'account-1', 'notified');
    }

    private static function rejected(string $eventId): PaymentRejected
    {
        return new PaymentRejected($eventId, '2026-11-15T10:00:00Z', 'tenant-1', 'payment-1', 'payment-ref-1', 'account-1', 'M', 2026, 10, ['ER_API_060_0070']);
    }

    private static function sepa(string $eventId): SepaMandateRegistered
    {
        return new SepaMandateRegistered($eventId, '2026-11-15T10:00:00Z', 'tenant-1', null, null, 'RUM-1');
    }

    /**
     * @param list<OutboxEntry> $entries
     *
     * @return list<string>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn(OutboxEntry $entry): string => $entry->event->eventId, $entries);
    }
}
