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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;

/**
 * Returns the views in the order of the query; unknown records and records of another tenant are left out. A state
 * listing returns the least recently updated first.
 */
final readonly class FindDeclarationsHandler
{
    public function __construct(private DeclarationRecordRepository $records, private WorkflowPolicy $policy) {}

    /**
     * @return list<DeclarationView>
     */
    public function __invoke(FindDeclarations $query): array
    {
        $found = $query->state instanceof DeclarationState
            ? $this->records->findByState($query->tenantId, $query->state, $this->policy->pageSize($query->limit))
            : array_map(fn(DeclarationRecordId $id): ?DeclarationRecord => $this->find($id), $query->recordIds);
        $views = [];
        foreach ($found as $record) {
            if (!$record instanceof DeclarationRecord || !$record->tenantId()->equals($query->tenantId)) {
                continue;
            }
            $views[] = DeclarationView::fromRecord($record);
        }

        return $views;
    }

    private function find(DeclarationRecordId $id): ?DeclarationRecord
    {
        try {
            return $this->records->get($id);
        } catch (RecordNotFound) {
            return null;
        }
    }
}
