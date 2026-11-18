<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Outbox;

use Override;
use Symfony\Component\Messenger\MessageBusInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;

/**
 * Delivers the integration events of the outbox to a Messenger bus (route them in the host application): a failing
 * dispatch keeps the event in the outbox, it is relayed again later (ADR 0014).
 */
final readonly class MessengerIntegrationEventSink implements IntegrationEventSink
{
    public function __construct(private MessageBusInterface $bus) {}

    #[Override]
    public function dispatch(IntegrationEvent $event): void
    {
        $this->bus->dispatch($event);
    }
}
