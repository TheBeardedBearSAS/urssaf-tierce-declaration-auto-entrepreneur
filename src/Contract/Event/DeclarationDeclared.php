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
 * URSSAF recorded the turnover declaration (method 050 accepted, or manual resolution of an unknown outcome, ADR 0003).
 * Amounts are never published (ADR 0010).
 *
 * - aggregateId: the declaration record id;
 * - accountId: the id of the tracked account, null when the SIRET is not tracked;
 * - periodicity, periodYear, periodNumber: the declaration period (M monthly or T quarterly, the year, the month or
 *   the quarter number);
 * - certificat: the URSSAF certificate of the declaration;
 * - idDeclaration: the URSSAF declaration id, null when the answer carried none (an operator resolution).
 */
final readonly class DeclarationDeclared extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.declaration.declared';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        ?string $aggregateId,
        ?string $externalReference,
        public ?string $accountId,
        public string $periodicity,
        public int $periodYear,
        public int $periodNumber,
        public string $certificat,
        public ?string $idDeclaration,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
