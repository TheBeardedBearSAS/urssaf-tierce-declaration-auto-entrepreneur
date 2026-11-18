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
 * The payment order may have reached URSSAF without a usable answer: never resent, an operator must check it (ADR 0003).
 *
 * - aggregateId: the payment record id;
 * - externalReference: the PaymentReference of the payment;
 * - accountId: the id of the tracked account, null when the SIRET is not tracked;
 * - periodicity, periodYear, periodNumber: the declaration period (M monthly or T quarterly, the year, the month or
 *   the quarter number);
 * - reason: why the outcome is unknown (the OutcomeUnknownReason value).
 */
final readonly class PaymentOutcomeUnknown extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.payment.outcome_unknown';

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
        public string $reason,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
