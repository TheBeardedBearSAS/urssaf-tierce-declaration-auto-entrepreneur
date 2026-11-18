<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;

/**
 * Designates a declaration record of a tenant: by record id, or as the current record of a SIRET and a period (the host
 * key of the declaration, ADR 0009). The reference is masked in debug output (it holds a SIRET).
 */
final readonly class DeclarationSelector
{
    private function __construct(public DeclarationRecordId|DeclarationKey $reference) {}

    public static function byRecordId(DeclarationRecordId $recordId): self
    {
        return new self($recordId);
    }

    public static function current(Siret $siret, DeclarationPeriod $period): self
    {
        return new self(new DeclarationKey($siret, $period));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['reference' => '***'];
    }
}
