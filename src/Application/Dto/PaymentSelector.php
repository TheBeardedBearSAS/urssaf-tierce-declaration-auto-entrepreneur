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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;

/**
 * Designates a payment record of a tenant: by record id or by the host payment reference (ADR 0009, ADR 0014).
 */
final readonly class PaymentSelector
{
    private function __construct(
        public PaymentRecordId|PaymentReference $reference,
    ) {}

    public static function byRecordId(PaymentRecordId $recordId): self
    {
        return new self($recordId);
    }

    public static function byReference(PaymentReference $reference): self
    {
        return new self($reference);
    }
}
