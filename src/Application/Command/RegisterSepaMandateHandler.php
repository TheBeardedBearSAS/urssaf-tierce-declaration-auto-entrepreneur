<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\SepaMandateEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;

/**
 * Registers a SEPA mandate (method 070) and returns the mandate as URSSAF answered it (ADR 0009, ADR 0014 item 5).
 *
 * Pass-through: there is no local aggregate. Every failure of the call propagates and writes no event
 * ({@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected}
 * "sepa_mandate_already_exists", "invalid_bank_details", an unknown outcome, authentication, unavailability): after an
 * unknown outcome, check with 075 (ListSepaMandates, ADR 0003). When URSSAF answered a RUM, the integration event
 * SepaMandateRegistered (the RUM only) is appended to the outbox in a transaction of its own; a failure of that append
 * propagates unchanged although 070 was accepted: the event alone is missing, check the mandate with 075. An answer
 * without RUM gives no event (nothing to identify).
 */
final readonly class RegisterSepaMandateHandler
{
    public function __construct(
        private SepaMandateRegistrar $registrar,
        private TransactionManager $transactions,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    public function __invoke(RegisterSepaMandate $command): SepaMandate
    {
        $result = $this->registrar->registerSepaMandate($command->tenantId, $command->siret, $command->mandate);
        $rum = $result->rum();
        if ($rum instanceof Rum) {
            $this->transactions->transactional(function () use ($command, $rum): void {
                $this->outbox->append(SepaMandateEvents::registered($command->tenantId, $rum, $this->clock->now()));
            });
        }

        return $result;
    }
}
