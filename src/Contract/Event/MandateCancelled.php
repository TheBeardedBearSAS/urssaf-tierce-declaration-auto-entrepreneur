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
 * The mandate of an auto-entrepreneur was cancelled (method 031 accepted) or the cancellation was recorded without a
 * call (ADR 0009).
 *
 * - aggregateId: the mandate id;
 * - accountId: the id of the tracked account, null when the SIRET is not tracked;
 * - origin: "cancelled" or "recorded".
 */
final readonly class MandateCancelled extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.mandate.cancelled';

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
