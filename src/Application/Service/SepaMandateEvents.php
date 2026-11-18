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

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRevoked;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Integration events of the SEPA mandates (methods 070 / 071). They are pass-through: there is no local aggregate and
 * no domain event (ADR 0009), so the 070 / 071 handlers build them here and append them to the outbox in their own
 * transaction (ADR 0014 item 5). Both have a null aggregate id and a null external reference.
 */
final class SepaMandateEvents
{
    public static function registered(TenantId $tenantId, Rum $rum, DateTimeImmutable $occurredAt): SepaMandateRegistered
    {
        return new SepaMandateRegistered(
            IntegrationEventFacts::id(SepaMandateRegistered::TYPE, $tenantId->value(), null, $occurredAt, [$rum->value()]),
            IntegrationEventFacts::instant($occurredAt),
            $tenantId->value(),
            null,
            null,
            $rum->value(),
        );
    }

    public static function revoked(TenantId $tenantId, Rum $rum, DateTimeImmutable $occurredAt): SepaMandateRevoked
    {
        return new SepaMandateRevoked(
            IntegrationEventFacts::id(SepaMandateRevoked::TYPE, $tenantId->value(), null, $occurredAt, [$rum->value()]),
            IntegrationEventFacts::instant($occurredAt),
            $tenantId->value(),
            null,
            null,
            $rum->value(),
        );
    }
}
