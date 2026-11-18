<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The local mandate guard of the paths that send (ADR 0011 item 1), keyed by SIRET. A synced account whose snapshot says
 * "mandatePresent" never satisfies it: only a local active mandate does.
 */
final readonly class MandateGuard
{
    public function __construct(private MandateRepository $mandates, private WorkflowPolicy $policy) {}

    /**
     * The flag is the opt-out of the command ("skipMandateCheck", ADR 0011), passed through as is.
     *
     * @mago-expect lint:no-boolean-flag-parameter
     *
     * @throws MandateRequired "mandate.required"
     */
    public function assertMandate(
        TenantId $tenantId,
        Siret $siret,
        Operation $operation,
        bool $skipMandateCheck,
    ): void {
        if ($skipMandateCheck || !$this->policy->requireLocalMandate()) {
            return;
        }
        if ($this->mandates->findActive($tenantId, $siret) instanceof Mandate) {
            return;
        }

        throw MandateRequired::forOperation($operation);
    }
}
