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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationKey;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Loads the declaration record designated by a {@see DeclarationSelector}, within one tenant.
 */
final readonly class DeclarationLocator
{
    private const RECORD_TYPE = 'declaration_record';

    public function __construct(private DeclarationRecordRepository $records) {}

    /**
     * @throws RecordNotFound "declaration_record.not_found" (also for a record of another tenant). The identifier of a
     *                        lookup by SIRET and period is masked: the SIRET is never an identifier (ADR 0010 item 5).
     */
    public function locate(TenantId $tenantId, DeclarationSelector $selector): DeclarationRecord
    {
        $reference = $selector->reference;
        if ($reference instanceof DeclarationKey) {
            return $this->records->findCurrent($tenantId, $reference->siret, $reference->period)
                ?? throw RecordNotFound::withIdentifier(self::RECORD_TYPE, '***');
        }
        $record = $this->records->get($reference);
        if (!$record->tenantId()->equals($tenantId)) {
            throw RecordNotFound::withIdentifier(self::RECORD_TYPE, $reference->value());
        }

        return $record;
    }
}
