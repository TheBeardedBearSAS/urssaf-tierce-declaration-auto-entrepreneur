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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Reads payment records of a tenant by payment references or by record ids (unknown ones are left out), or lists the ones
 * in a state (least recently updated first, limit capped by the workflow policy, ADR 0014).
 */
final readonly class FindPayments
{
    /**
     * @param list<PaymentReference> $references
     * @param list<PaymentRecordId>  $recordIds
     */
    private function __construct(
        public TenantId $tenantId,
        public array $references,
        public array $recordIds,
        public ?PaymentState $state,
        public ?int $limit,
    ) {}

    public static function byRecordIds(TenantId $tenantId, PaymentRecordId ...$recordIds): self
    {
        return new self($tenantId, [], array_values($recordIds), null, null);
    }

    public static function byReferences(TenantId $tenantId, PaymentReference ...$references): self
    {
        return new self($tenantId, array_values($references), [], null, null);
    }

    public static function inState(TenantId $tenantId, PaymentState $state, ?int $limit): self
    {
        return new self($tenantId, [], [], $state, $limit);
    }
}
