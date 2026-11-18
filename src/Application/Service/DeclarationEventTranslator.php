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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationDeclared as DomainDeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationOutcomeUnknown as DomainDeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRejected as DomainDeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

/**
 * Integration events of the declaration workflow (ADR 0014). Recorded, requeued, technically failed and
 * rejected-credentials declarations have none: they are not final facts for the host (item 4). The account id is null
 * when the SIRET is not tracked.
 */
final class DeclarationEventTranslator
{
    /**
     * @return list<IntegrationEvent> the integration event of the domain event, none when it has none
     */
    public static function translate(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof DomainDeclarationDeclared => [self::declared($event)],
            $event instanceof DomainDeclarationRejected => [self::rejected($event)],
            $event instanceof DomainDeclarationOutcomeUnknown => [self::outcomeUnknown($event)],
            default => [],
        };
    }

    private static function declared(DomainDeclarationDeclared $event): DeclarationDeclared
    {
        $aggregateId = $event->recordId()->value();
        $tenantId = $event->tenantId()->value();
        $certificat = $event->certificate()->value();
        $idDeclaration = $event->declarationId();
        [$periodicity, $year, $number] = IntegrationEventFacts::period($event->period());

        return new DeclarationDeclared(
            IntegrationEventFacts::id(DeclarationDeclared::TYPE, $tenantId, $aggregateId, $event->occurredAt(), [$certificat, $idDeclaration]),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $event->accountId()?->value(),
            $periodicity,
            $year,
            $number,
            $certificat,
            $idDeclaration,
        );
    }

    private static function rejected(DomainDeclarationRejected $event): DeclarationRejected
    {
        $aggregateId = $event->recordId()->value();
        $tenantId = $event->tenantId()->value();
        $codes = $event->errorCodes();
        [$periodicity, $year, $number] = IntegrationEventFacts::period($event->period());

        return new DeclarationRejected(
            IntegrationEventFacts::id(DeclarationRejected::TYPE, $tenantId, $aggregateId, $event->occurredAt(), $codes),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $event->accountId()?->value(),
            $periodicity,
            $year,
            $number,
            $codes,
        );
    }

    private static function outcomeUnknown(DomainDeclarationOutcomeUnknown $event): DeclarationOutcomeUnknown
    {
        $aggregateId = $event->recordId()->value();
        $tenantId = $event->tenantId()->value();
        $reason = $event->reason()->value;
        [$periodicity, $year, $number] = IntegrationEventFacts::period($event->period());

        return new DeclarationOutcomeUnknown(
            IntegrationEventFacts::id(DeclarationOutcomeUnknown::TYPE, $tenantId, $aggregateId, $event->occurredAt(), [$reason]),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $event->accountId()?->value(),
            $periodicity,
            $year,
            $number,
            $reason,
        );
    }
}
