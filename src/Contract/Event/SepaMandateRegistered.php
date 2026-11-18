<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event;

/**
 * URSSAF accepted a SEPA mandate (method 070, pass-through: the package keeps no local copy, ADR 0009). Bank data is
 * never published (ADR 0010).
 *
 * - aggregateId, externalReference: always null;
 * - rum: the unique mandate reference.
 */
final readonly class SepaMandateRegistered extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.sepa_mandate.registered';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        ?string $aggregateId,
        ?string $externalReference,
        public string $rum,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
