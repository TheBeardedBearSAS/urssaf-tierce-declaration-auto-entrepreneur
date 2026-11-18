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
 * URSSAF refused the turnover declaration (method 050, final).
 *
 * - aggregateId: the declaration record id;
 * - accountId: the id of the tracked account, null when the SIRET is not tracked;
 * - periodicity, periodYear, periodNumber: the declaration period (M monthly or T quarterly, the year, the month or
 *   the quarter number);
 * - errorCodes: the URSSAF error codes, never their text.
 */
final readonly class DeclarationRejected extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.declaration.rejected';

    /**
     * @param list<string> $errorCodes
     */
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
        public array $errorCodes,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
