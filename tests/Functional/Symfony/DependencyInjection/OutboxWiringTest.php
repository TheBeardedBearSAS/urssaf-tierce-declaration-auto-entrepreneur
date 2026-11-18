<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\Psr14IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;

/**
 * The outbox end to end: written in the transaction of a state change, relayed to the configured sink, purged.
 */
final class OutboxWiringTest extends TestCase
{
    public function testWithoutTheOutboxNothingIsWritten(): void
    {
        $harness = Harness::boot();

        Scenario::importedMandate($harness);

        static::assertInstanceOf(IgnoredOutbox::class, $harness->privateService(Outbox::class, Outbox::class));
        static::assertSame([], $harness->privateService(Outbox::class, Outbox::class)->pending(10));
    }

    public function testAStateChangeLeavesAnEntryThatTheRelayDeliversToTheEventDispatcher(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'event_dispatcher']]);
        $received = [];
        $harness->privateService('event_dispatcher', EventDispatcherInterface::class)->addListener(
            MandateNotified::class,
            static function (IntegrationEvent $event) use (&$received): void {
                $received[] = $event;
            },
        );

        Scenario::importedMandate($harness);
        $pending = $harness->privateService(Outbox::class, Outbox::class)->pending(10);
        $report = $harness->get(RelayOutboxHandler::class)(new RelayOutbox(null));

        static::assertInstanceOf(InMemoryOutbox::class, $harness->privateService(Outbox::class, Outbox::class));
        static::assertInstanceOf(Psr14IntegrationEventSink::class, $harness->privateService(IntegrationEventSink::class, IntegrationEventSink::class));
        static::assertCount(1, $pending);
        static::assertSame(1, $report->dispatched);
        static::assertSame(0, $report->failed);
        static::assertCount(1, $received);
        static::assertSame([], $harness->privateService(Outbox::class, Outbox::class)->pending(10));
    }

    public function testTheSinkAndTheOutboxMayBeServicesOfTheApplication(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'service' => 'test.outbox', 'sink' => 'test.sink']]);

        Scenario::importedMandate($harness);
        $harness->get(RelayOutboxHandler::class)(new RelayOutbox(null));

        static::assertSame($harness->privateService('test.outbox', Outbox::class), $harness->privateService(Outbox::class, Outbox::class));
        static::assertCount(1, $harness->privateService('test.sink', RecordingIntegrationEventSink::class)->deliveredIds());
    }

    public function testTheDispatchedEntriesArePurgedAfterTheRetention(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink', 'retention' => 'P7D']]);
        Scenario::importedMandate($harness);
        $harness->get(RelayOutboxHandler::class)(new RelayOutbox(null));

        static::assertSame(0, $harness->get(PurgeOutboxHandler::class)(new PurgeOutbox(null)));

        $harness->clock->modify('+8 days');

        static::assertSame(1, $harness->get(PurgeOutboxHandler::class)(new PurgeOutbox(null)));
    }
}
