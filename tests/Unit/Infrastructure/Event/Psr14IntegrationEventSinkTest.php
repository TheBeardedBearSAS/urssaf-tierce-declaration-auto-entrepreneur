<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Event;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\Psr14IntegrationEventSink;

final class Psr14IntegrationEventSinkTest extends TestCase
{
    public function testDispatchesEachIntegrationEventThroughThePsr14Dispatcher(): void
    {
        $dispatcher = new RecordingEventDispatcher(null);
        $first = new MandateNotified('e1', '2026-11-15T10:00:00Z', 'tenant-1', 'mandate-1', null, 'account-1', 'notified');
        $second = new MandateNotified('e2', '2026-11-15T10:00:00Z', 'tenant-1', 'mandate-2', null, null, 'imported');
        $sink = new Psr14IntegrationEventSink($dispatcher);

        $sink->dispatch($first);
        $sink->dispatch($second);

        static::assertSame([$first, $second], $dispatcher->dispatched());
    }

    public function testALoggedListenerFailureReachesTheCaller(): void
    {
        $failure = new RuntimeException('listener down', 7);
        $sink = new Psr14IntegrationEventSink(new RecordingEventDispatcher($failure));

        $this->expectExceptionObject($failure);

        $sink->dispatch(new MandateNotified('e1', '2026-11-15T10:00:00Z', 'tenant-1', 'mandate-1', null, null, 'notified'));
    }
}
