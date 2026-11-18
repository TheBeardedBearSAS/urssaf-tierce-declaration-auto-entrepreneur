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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DomainEventSamples;

final class RecordedEventsTest extends TestCase
{
    public function testRecordedEventsKeepTheReleaseOrder(): void
    {
        $first = DomainEventSamples::declarationRecorded();
        $second = DomainEventSamples::declarationRequeued();
        $events = new RecordedEvents();

        static::assertSame([], $events->all());
        $events->record();
        static::assertSame([], $events->all());
        $events->record(...['first' => $first, 'second' => $second]);
        $events->record($first);

        static::assertSame([$first, $second, $first], $events->all());
    }
}
