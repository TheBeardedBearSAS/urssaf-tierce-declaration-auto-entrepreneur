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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Loads the payment record designated by a {@see PaymentSelector}, within one tenant.
 */
final readonly class PaymentLocator
{
    private const RECORD_TYPE = 'payment_record';

    public function __construct(
        private PaymentRecordRepository $records,
    ) {}

    /**
     * @throws RecordNotFound "payment_record.not_found" (also for a record of another tenant)
     */
    public function locate(TenantId $tenantId, PaymentSelector $selector): PaymentRecord
    {
        $reference = $selector->reference;
        if ($reference instanceof PaymentReference) {
            // The payment reference is the host's key, not personal data.
            return $this->records->findByReference($tenantId, $reference)
                ?? throw RecordNotFound::withIdentifier(self::RECORD_TYPE, $reference->value());
        }
        $record = $this->records->get($reference);
        if (!$record->tenantId()->equals($tenantId)) {
            throw RecordNotFound::withIdentifier(self::RECORD_TYPE, $reference->value());
        }

        return $record;
    }
}
