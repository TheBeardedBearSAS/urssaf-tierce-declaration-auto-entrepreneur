<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\PaymentRecordIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\PaymentReferenceType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Key row of a current payment record: the primary key (tenant, payment reference) is the "one current payment per
 * payment reference" constraint (ADR 0009, ADR 0016). Mapped so that the schema tools and migrations know the table;
 * written with DBAL by {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrinePaymentRecordRepository} in the transaction of
 * the record flush, never through the ORM.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity: final by convention, never extend it.
 *
 * @final
 */
class CurrentPayment
{
    /** DBAL types of the columns of {@see self::row()}. */
    public const TYPES = [
        'tenant_id' => TenantIdType::NAME,
        'payment_reference' => PaymentReferenceType::NAME,
        'payment_record_id' => PaymentRecordIdType::NAME,
    ];

    private function __construct(
        protected TenantId $tenantId,
        protected PaymentReference $paymentReference,
        private readonly PaymentRecordId $paymentRecordId,
    ) {}

    public static function of(PaymentRecord $record): self
    {
        return new self($record->tenantId(), $record->reference(), $record->id());
    }

    /**
     * The columns of the row, keyed by column name (see {@see self::TYPES}).
     *
     * @return array{tenant_id: TenantId, payment_reference: PaymentReference, payment_record_id: PaymentRecordId}
     */
    public function row(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'payment_reference' => $this->paymentReference,
            'payment_record_id' => $this->paymentRecordId,
        ];
    }
}
