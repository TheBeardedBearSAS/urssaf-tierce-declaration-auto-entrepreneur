<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Orders the payment of the contributions of a period (method 060, ADR 0009).
 *
 * The payment reference is the host's idempotency key: ordering again the same reference with the same content never
 * orders twice. The order holds the IBAN, which is passed to URSSAF and never stored: a host retry passes it again.
 * "skipMandateCheck" opts out of the local mandate guard, "allowAdditionalPayment" of the second-payment guard (an
 * intended second payment for a period that already has one, ADR 0011).
 */
final readonly class PayContributions
{
    public function __construct(
        public TenantId $tenantId,
        public PaymentReference $reference,
        public Siret $siret,
        #[SensitiveParameter]
        public PaymentOrder $order,
        public bool $skipMandateCheck = false,
        public bool $allowAdditionalPayment = false,
    ) {}
}
