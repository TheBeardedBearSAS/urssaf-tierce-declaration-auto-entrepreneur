<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Reads declaration records of a tenant by record ids (unknown ones are left out), or lists the ones in a state (least
 * recently updated first, limit capped by the workflow policy).
 */
final readonly class FindDeclarations
{
    /**
     * @param list<DeclarationRecordId> $recordIds
     */
    private function __construct(
        public TenantId $tenantId,
        public array $recordIds,
        public ?DeclarationState $state,
        public ?int $limit,
    ) {}

    public static function byRecordIds(TenantId $tenantId, DeclarationRecordId ...$recordIds): self
    {
        return new self($tenantId, array_values($recordIds), null, null);
    }

    public static function inState(TenantId $tenantId, DeclarationState $state, ?int $limit): self
    {
        return new self($tenantId, [], $state, $limit);
    }
}
