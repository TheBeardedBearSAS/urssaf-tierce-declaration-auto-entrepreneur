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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\DeclarationRecordIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Key row of a current declaration record: the primary key (tenant, SIRET, periodicity, year, number) is the "one
 * current declaration per SIRET and period" constraint (ADR 0009, ADR 0016). Mapped so that the schema tools and
 * migrations know the table; written with DBAL by
 * {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineDeclarationRecordRepository} in the transaction of the record flush,
 * never through the ORM.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity: final by convention, never extend it.
 *
 * @final
 */
class CurrentDeclaration
{
    /** DBAL types of the columns of {@see self::row()}. */
    public const TYPES = [
        'tenant_id' => TenantIdType::NAME,
        'siret' => SiretType::NAME,
        'periodicity' => 'string',
        'period_year' => 'smallint',
        'period_number' => 'smallint',
        'declaration_record_id' => DeclarationRecordIdType::NAME,
    ];

    private function __construct(
        protected TenantId $tenantId,
        protected Siret $siret,
        protected string $periodicity,
        protected int $periodYear,
        protected int $periodNumber,
        private readonly DeclarationRecordId $declarationRecordId,
    ) {}

    public static function of(DeclarationRecord $record): self
    {
        $period = $record->period();

        return new self(
            $record->tenantId(),
            $record->siret(),
            $period->periodicity()->value,
            $period->year(),
            DeclarationPeriods::numberOf($period),
            $record->id(),
        );
    }

    /**
     * The columns of the row, keyed by column name (see {@see self::TYPES}).
     *
     * @return array{tenant_id: TenantId, siret: Siret, periodicity: string, period_year: int, period_number: int, declaration_record_id: DeclarationRecordId}
     */
    public function row(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'siret' => $this->siret,
            'periodicity' => $this->periodicity,
            'period_year' => $this->periodYear,
            'period_number' => $this->periodNumber,
            'declaration_record_id' => $this->declarationRecordId,
        ];
    }
}
