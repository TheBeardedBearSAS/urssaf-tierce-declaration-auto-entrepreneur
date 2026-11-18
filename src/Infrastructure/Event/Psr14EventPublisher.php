<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

/**
 * Event port dispatching each domain event, in order, through a PSR-14 dispatcher.
 */
final readonly class Psr14EventPublisher implements EventPublisher
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatcher->dispatch($event);
        }
    }
}
