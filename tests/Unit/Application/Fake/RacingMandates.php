<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Mandate repository that saves another mandate after the first deletion, to simulate another process creating a mandate
 * while a SIRET is being erased.
 */
final class RacingMandates implements MandateRepository
{
    private bool $raced = false;

    public function __construct(
        private readonly MandateRepository $inner,
        private readonly Mandate $intruder,
    ) {}

    #[Override]
    public function save(Mandate $mandate): void
    {
        $this->inner->save($mandate);
    }

    #[Override]
    public function get(MandateId $id): Mandate
    {
        return $this->inner->get($id);
    }

    #[Override]
    public function findActive(TenantId $tenantId, Siret $siret): ?Mandate
    {
        return $this->inner->findActive($tenantId, $siret);
    }

    #[Override]
    public function findBySiret(TenantId $tenantId, Siret $siret): array
    {
        return $this->inner->findBySiret($tenantId, $siret);
    }

    #[Override]
    public function delete(MandateId $id): void
    {
        $this->inner->delete($id);
        if (!$this->raced) {
            $this->raced = true;
            $this->inner->save($this->intruder);
        }
    }
}
