<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Counts the records of the in-memory repositories (the AE account has no state and is not counted).
 */
final readonly class InMemoryWorkflowStatistics implements WorkflowStatistics
{
    public function __construct(
        private InMemoryMandateRepository $mandates,
        private InMemoryDeclarationRecordRepository $declarations,
        private InMemoryPaymentRecordRepository $payments,
    ) {}

    #[Override]
    public function mandateStates(): array
    {
        return self::count(array_map(
            static fn(Mandate $mandate): array => [$mandate->tenantId(), $mandate->state()->value],
            $this->mandates->all(),
        ));
    }

    #[Override]
    public function declarationStates(): array
    {
        return self::count(array_map(
            static fn(DeclarationRecord $record): array => [$record->tenantId(), $record->state()->value],
            $this->declarations->all(),
        ));
    }

    #[Override]
    public function paymentStates(): array
    {
        return self::count(array_map(
            static fn(PaymentRecord $record): array => [$record->tenantId(), $record->state()->value],
            $this->payments->all(),
        ));
    }

    /**
     * @param list<array{TenantId, string}> $records tenant and state of each record
     *
     * @return list<StateCount>
     */
    private static function count(array $records): array
    {
        $tenants = [];
        $counts = [];
        foreach ($records as [$tenantId, $state]) {
            $tenants[$tenantId->value()] = $tenantId;
            $counts[$tenantId->value()][$state] = ($counts[$tenantId->value()][$state] ?? 0) + 1;
        }
        $stateCounts = [];
        foreach ($counts as $tenant => $byState) {
            foreach ($byState as $state => $count) {
                $stateCounts[] = new StateCount($tenants[$tenant], $state, $count);
            }
        }

        return $stateCounts;
    }
}
