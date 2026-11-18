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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

/**
 * Turns the domain events that matter to the host application into public integration events; the others (recorded,
 * requeued or technically failed submissions...) have none (ADR 0014 item 4).
 */
final class IntegrationEventTranslator
{
    /**
     * @return list<IntegrationEvent>
     */
    public static function translate(DomainEvent ...$events): array
    {
        $translated = [];
        foreach ($events as $event) {
            array_push(
                $translated,
                ...AccountEventTranslator::translate($event),
                ...MandateEventTranslator::translate($event),
                ...DeclarationEventTranslator::translate($event),
                ...PaymentEventTranslator::translate($event),
            );
        }

        return $translated;
    }
}
