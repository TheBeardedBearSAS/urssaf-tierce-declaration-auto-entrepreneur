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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\SepaMandateEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;

/**
 * Revokes a SEPA mandate (method 071, ADR 0009, ADR 0011 item 4).
 *
 * Unless "skipEffectiveDateCheck", an effective date that is not after today in the business time zone is refused before
 * any call ({@see ValidationFailed} "sepa_revocation.effective_date_too_early"); an omitted date passes and is not sent.
 * Every failure of the call propagates and writes no event. After success, the integration event SepaMandateRevoked (the
 * RUM only) is appended to the outbox in a transaction of its own; a failure of that append propagates unchanged although
 * 071 was accepted: the event alone is missing, check the mandate with 075.
 */
final readonly class RevokeSepaMandateHandler
{
    public function __construct(
        private SepaMandateRevoker $revoker,
        private TransactionManager $transactions,
        private Outbox $outbox,
        private Clock $clock,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws ValidationFailed "sepa_revocation.effective_date_too_early" (path "effective_date"), nothing was sent
     */
    public function __invoke(RevokeSepaMandate $command): void
    {
        if (!$command->skipEffectiveDateCheck) {
            $command->revocation->effectiveDateViolations($this->policy->today($this->clock->now()))->throwIfInvalid();
        }
        $this->revoker->revokeSepaMandate($command->tenantId, $command->revocation);
        $this->transactions->transactional(function () use ($command): void {
            $this->outbox->append(SepaMandateEvents::revoked($command->tenantId, $command->revocation->rum(), $this->clock->now()));
        });
    }
}
