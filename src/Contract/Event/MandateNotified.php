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
 * The mandate of an auto-entrepreneur was notified to URSSAF (method 030 accepted) or imported (ADR 0009).
 *
 * - aggregateId: the mandate id;
 * - accountId: the id of the tracked account, null when the SIRET is not tracked;
 * - origin: "notified" or "imported".
 */
final readonly class MandateNotified extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.mandate.notified';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        ?string $aggregateId,
        ?string $externalReference,
        public ?string $accountId,
        public string $origin,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
