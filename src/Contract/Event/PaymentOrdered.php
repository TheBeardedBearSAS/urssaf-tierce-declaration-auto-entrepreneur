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
 * URSSAF accepted the contribution payment order (method 060, or manual resolution of an unknown outcome, ADR 0003).
 * Amounts, due dates and bank data are never published (ADR 0010).
 *
 * - aggregateId: the payment record id;
 * - externalReference: the PaymentReference of the payment;
 * - accountId: the id of the tracked account, null when the SIRET is not tracked;
 * - periodicity, periodYear, periodNumber: the declaration period (M monthly or T quarterly, the year, the month or
 *   the quarter number);
 * - certificat: the certificate of the declaration paid;
 * - idTelepaiement: the URSSAF payment id, null when the answer carried none;
 * - rum: the SEPA mandate reference used, null when none was given.
 */
final readonly class PaymentOrdered extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.payment.ordered';

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
        public ?string $idTelepaiement,
        public ?string $rum,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
