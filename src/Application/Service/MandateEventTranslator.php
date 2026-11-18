<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateCancelled as DomainMandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateNotified as DomainMandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

/**
 * Integration events of the local mandates (ADR 0009, ADR 0014).
 */
final class MandateEventTranslator
{
    /**
     * @return list<IntegrationEvent> the integration event of the domain event, none when it has none
     */
    public static function translate(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof DomainMandateNotified => [self::notified($event)],
            $event instanceof DomainMandateCancelled => [self::cancelled($event)],
            default => [],
        };
    }

    private static function notified(DomainMandateNotified $event): MandateNotified
    {
        $aggregateId = $event->mandateId()->value();
        $tenantId = $event->tenantId()->value();
        $origin = $event->origin()->value;

        return new MandateNotified(
            IntegrationEventFacts::id(MandateNotified::TYPE, $tenantId, $aggregateId, $event->occurredAt(), [$origin]),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $event->accountId()?->value(),
            $origin,
        );
    }

    private static function cancelled(DomainMandateCancelled $event): MandateCancelled
    {
        $aggregateId = $event->mandateId()->value();
        $tenantId = $event->tenantId()->value();
        $origin = $event->origin()->value;

        return new MandateCancelled(
            IntegrationEventFacts::id(MandateCancelled::TYPE, $tenantId, $aggregateId, $event->occurredAt(), [$origin]),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $event->accountId()?->value(),
            $origin,
        );
    }
}
