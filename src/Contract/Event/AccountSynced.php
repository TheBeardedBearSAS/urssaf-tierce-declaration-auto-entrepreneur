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
 * Method 020 observed a change of the mandate presence or of the periodicity of a tracked account (ADR 0011, ADR 0014).
 * It is emitted on a change only, so that the daily sync does not flood the outbox: the previous values are null on
 * the first read.
 *
 * - aggregateId: the account id;
 * - previousMandatePresent, mandatePresent: the "mandatPresent" flag before and after (a false after a true means that
 *   the mandate was cancelled out of band);
 * - previousPeriodicity, periodicity: the periodicity code (M or T) before and after.
 */
final readonly class AccountSynced extends IntegrationEvent
{
    public const TYPE = 'urssaf.tdae.account.synced';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        ?string $aggregateId,
        ?string $externalReference,
        public ?bool $previousMandatePresent,
        public bool $mandatePresent,
        public ?string $previousPeriodicity,
        public string $periodicity,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId, $externalReference);
    }
}
