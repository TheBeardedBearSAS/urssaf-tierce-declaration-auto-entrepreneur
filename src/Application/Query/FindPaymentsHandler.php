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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;

/**
 * Returns the views in the order of the query (references first, then ids); unknown records and records of another
 * tenant are left out. A state listing returns the least recently updated first.
 */
final readonly class FindPaymentsHandler
{
    public function __construct(
        private PaymentRecordRepository $records,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @return list<PaymentView>
     */
    public function __invoke(FindPayments $query): array
    {
        $found = $query->state instanceof PaymentState
            ? $this->records->findByState($query->tenantId, $query->state, $this->policy->pageSize($query->limit))
            : [
                ...array_map(
                    fn(PaymentReference $reference): ?PaymentRecord => $this->records->findByReference($query->tenantId, $reference),
                    $query->references,
                ),
                ...array_map(fn(PaymentRecordId $id): ?PaymentRecord => $this->find($id), $query->recordIds),
            ];
        $views = [];
        foreach ($found as $record) {
            if (!$record instanceof PaymentRecord || !$record->tenantId()->equals($query->tenantId)) {
                continue;
            }
            $views[] = PaymentView::fromRecord($record);
        }

        return $views;
    }

    private function find(PaymentRecordId $id): ?PaymentRecord
    {
        try {
            return $this->records->get($id);
        } catch (RecordNotFound) {
            return null;
        }
    }
}
