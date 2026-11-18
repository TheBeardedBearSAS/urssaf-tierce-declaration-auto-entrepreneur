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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\TenantOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The records by tenant and state, for dashboards and alerting (ADR 0003, ADR 0009): mandates, declarations and payments,
 * plus the declarations accepted after their supersession.
 */
final readonly class GetWorkflowOverviewHandler
{
    public function __construct(
        private WorkflowStatistics $statistics,
        private DeclarationRecordRepository $records,
    ) {}

    /**
     * @return list<TenantOverview> one entry per tenant (sorted by id) that has records, or exactly one for the requested tenant
     */
    public function __invoke(GetWorkflowOverview $query): array
    {
        $mandates = $this->byTenant($this->statistics->mandateStates(), $query);
        $declarations = $this->byTenant($this->statistics->declarationStates(), $query);
        $payments = $this->byTenant($this->statistics->paymentStates(), $query);
        // A numeric tenant id ("123") becomes an int array key: the keys are cast back to strings explicitly.
        $tenants = $query->tenantId instanceof TenantId
            ? [$query->tenantId->value()]
            : array_map(strval(...), array_keys($mandates + $declarations + $payments));
        sort($tenants, SORT_STRING);

        $overviews = [];
        foreach ($tenants as $tenant) {
            $overviews[] = new TenantOverview(
                $tenant,
                [...self::zeros(MandateState::cases()), ...$mandates[$tenant] ?? []],
                [...self::zeros(DeclarationState::cases()), ...$declarations[$tenant] ?? []],
                [...self::zeros(PaymentState::cases()), ...$payments[$tenant] ?? []],
                $this->records->countAcceptedAfterSupersession(TenantId::fromString($tenant)),
            );
        }

        return $overviews;
    }

    /**
     * @param list<StateCount> $counts
     *
     * @return array<string, array<string, int>>
     */
    private function byTenant(array $counts, GetWorkflowOverview $query): array
    {
        $byTenant = [];
        foreach ($counts as $count) {
            if ($query->tenantId instanceof TenantId && !$count->tenantId->equals($query->tenantId)) {
                continue;
            }
            $byTenant[$count->tenantId->value()][$count->state] = $count->count;
        }

        return $byTenant;
    }

    /**
     * @param list<MandateState>|list<DeclarationState>|list<PaymentState> $states
     *
     * @return array<string, int>
     */
    private static function zeros(array $states): array
    {
        return array_fill_keys(
            array_map(static fn(MandateState|DeclarationState|PaymentState $state): string => $state->value, $states),
            0,
        );
    }
}
