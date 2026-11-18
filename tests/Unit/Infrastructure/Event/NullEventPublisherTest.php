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

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\NullEventPublisher;

final class NullEventPublisherTest extends TestCase
{
    public function testDiscardsEventsWithoutFailing(): void
    {
        $publisher = new NullEventPublisher();

        $publisher->publish(new FakeDomainEvent('first', new DateTimeImmutable('2026-01-01T10:00:00+00:00')));
        $publisher->publish();

        $this->expectNotToPerformAssertions();
    }
}
