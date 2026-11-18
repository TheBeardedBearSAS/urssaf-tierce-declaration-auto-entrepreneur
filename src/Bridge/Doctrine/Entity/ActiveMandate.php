<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\MandateIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Key row of a non-cancelled mandate: the primary key (tenant, SIRET) is the "one active mandate per SIRET" constraint
 * (ADR 0006, ADR 0016). Mapped so that the schema tools and migrations know the table; written with DBAL by
 * {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineMandateRepository}
 * in the transaction of the mandate flush, never
 * through the ORM.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity: final by convention, never extend it.
 *
 * @final
 */
class ActiveMandate
{
    /** DBAL types of the columns of {@see self::row()}. */
    public const TYPES = [
        'tenant_id' => TenantIdType::NAME,
        'siret' => SiretType::NAME,
        'mandate_id' => MandateIdType::NAME,
    ];

    private function __construct(
        protected TenantId $tenantId,
        protected Siret $siret,
        private readonly MandateId $mandateId,
    ) {}

    public static function of(Mandate $mandate): self
    {
        return new self($mandate->tenantId(), $mandate->siret(), $mandate->id());
    }

    /**
     * The columns of the row, keyed by column name (see {@see self::TYPES}).
     *
     * @return array{tenant_id: TenantId, siret: Siret, mandate_id: MandateId}
     */
    public function row(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'siret' => $this->siret,
            'mandate_id' => $this->mandateId,
        ];
    }
}
