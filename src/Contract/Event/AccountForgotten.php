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
 * The account and its records were erased from the package store (GDPR, ADR 0017): erase your copies. Nothing was
 * erased at URSSAF.
 *
 * - aggregateId: the (erased) account id, null when the erasure had no tracked account;
 * - mandateIds: the erased mandate ids (aggregateId of the mandate events), oldest first;
 * - declarationRecordIds: the erased declaration record ids (aggregateId of the declaration events), oldest first;
 * - paymentRecordIds: the erased payment record ids (aggregateId of the payment events), oldest first.
 */
final readonly class AccountForgotten extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.account.forgotten';

    /**
     * @param list<string> $mandateIds
     * @param list<string> $declarationRecordIds
     * @param list<string> $paymentRecordIds
     */
    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        ?string $aggregateId,
        ?string $externalReference,
        public array $mandateIds,
        public array $declarationRecordIds,
        public array $paymentRecordIds,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
