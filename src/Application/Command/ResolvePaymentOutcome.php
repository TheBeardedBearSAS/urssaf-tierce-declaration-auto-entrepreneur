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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Settles a payment whose outcome is unknown after the operator checked it on the auto-entrepreneur space (ADR 0003):
 * either URSSAF holds it ({@see self::asPaid()}, with its certificat) or it never received it
 * ({@see self::asNotReceived()}: the record is pending again, the host alone decides to call PayContributions again).
 */
final readonly class ResolvePaymentOutcome
{
    private function __construct(
        public TenantId $tenantId,
        public PaymentSelector $payment,
        public ?string $certificat,
    ) {}

    public static function asPaid(TenantId $tenantId, PaymentSelector $payment, string $certificat): self
    {
        return new self($tenantId, $payment, $certificat);
    }

    public static function asNotReceived(TenantId $tenantId, PaymentSelector $payment): self
    {
        return new self($tenantId, $payment, null);
    }
}
