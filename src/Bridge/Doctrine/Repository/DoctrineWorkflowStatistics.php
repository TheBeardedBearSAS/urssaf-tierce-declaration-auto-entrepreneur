<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository;

use BackedEnum;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * One GROUP BY query per table, served by the indexes "(tenant_id, state)" and "(tenant_id, state, updated_at)" of the
 * three tables (pairs without record are omitted). Ordered by tenant then state, so that the result does not depend on
 * the platform. The accounts have no state and are not counted.
 */
final readonly class DoctrineWorkflowStatistics implements WorkflowStatistics
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    #[Override]
    public function mandateStates(): array
    {
        return $this->count(Mandate::class, 'state');
    }

    #[Override]
    public function declarationStates(): array
    {
        return $this->count(DeclarationRecord::class, 'status.state');
    }

    #[Override]
    public function paymentStates(): array
    {
        return $this->count(PaymentRecord::class, 'status.state');
    }

    /**
     * @param class-string $entity
     *
     * @return list<StateCount>
     */
    private function count(string $entity, string $stateField): array
    {
        /** @var list<array{tenantId: TenantId, state: BackedEnum, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQuery(
                \sprintf(
                    'SELECT r.tenantId AS tenantId, r.%1$s AS state, COUNT(r.id) AS total FROM %2$s r'
                    . ' GROUP BY r.tenantId, r.%1$s ORDER BY r.tenantId ASC, r.%1$s ASC',
                    $stateField,
                    $entity,
                ),
            )
            ->getResult();

        return array_map(
            static fn(array $row): StateCount => new StateCount($row['tenantId'], (string) $row['state']->value, (int) $row['total']),
            $rows,
        );
    }
}
