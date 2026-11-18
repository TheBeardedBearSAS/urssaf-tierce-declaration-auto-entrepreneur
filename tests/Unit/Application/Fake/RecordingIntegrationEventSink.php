<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;

/**
 * Records the delivered events; refuses the events whose id it was given (a broker that is down for them).
 */
final class RecordingIntegrationEventSink implements IntegrationEventSink
{
    /** @var list<IntegrationEvent> */
    private array $delivered = [];

    /**
     * @param list<string> $refusedEventIds
     */
    public function __construct(private readonly array $refusedEventIds = []) {}

    #[Override]
    public function dispatch(IntegrationEvent $event): void
    {
        if (\in_array($event->eventId, $this->refusedEventIds, true)) {
            throw new RuntimeException('Broker down.', 7);
        }
        $this->delivered[] = $event;
    }

    /**
     * @return list<string> the ids of the delivered events, in order
     */
    public function deliveredIds(): array
    {
        return array_map(static fn(IntegrationEvent $event): string => $event->eventId, $this->delivered);
    }
}
