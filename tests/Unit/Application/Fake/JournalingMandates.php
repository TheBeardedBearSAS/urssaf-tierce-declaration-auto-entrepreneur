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

use Closure;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;

/**
 * Decorator over the production in-memory repository: journals every save ("save:<id>") and every delete
 * ("delete:<id>").
 */
final class JournalingMandates implements MandateRepository
{
    private readonly InMemoryMandateRepository $inner;

    /** @var (Closure(InMemoryMandateRepository): void)|null */
    private ?Closure $intrusion = null;

    public function __construct(private readonly Journal $journal)
    {
        $this->inner = new InMemoryMandateRepository();
    }

    public function inner(): InMemoryMandateRepository
    {
        return $this->inner;
    }

    /**
     * Stores without journaling (test setup).
     */
    public function add(Mandate ...$mandates): void
    {
        foreach ($mandates as $mandate) {
            $this->inner->save($mandate);
        }
    }

    /**
     * Runs $intrusion once, just before the next save reaches the store: another process writing in between.
     *
     * @param Closure(InMemoryMandateRepository): void $intrusion
     */
    public function beforeNextSave(Closure $intrusion): void
    {
        $this->intrusion = $intrusion;
    }

    #[Override]
    public function save(Mandate $mandate): void
    {
        $this->journal->add('save:' . $mandate->id()->value());
        if ($this->intrusion instanceof Closure) {
            $intrusion = $this->intrusion;
            $this->intrusion = null;
            $intrusion($this->inner);
        }
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
        $this->journal->add('delete:' . $id->value());
        $this->inner->delete($id);
    }
}
