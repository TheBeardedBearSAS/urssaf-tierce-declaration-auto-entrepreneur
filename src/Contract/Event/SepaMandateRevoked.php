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
 * URSSAF accepted the revocation of a SEPA mandate (method 071, 204).
 *
 * - aggregateId, externalReference: always null;
 * - rum: the unique mandate reference.
 */
final readonly class SepaMandateRevoked extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.sepa_mandate.revoked';

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
