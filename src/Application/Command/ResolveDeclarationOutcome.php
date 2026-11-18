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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Settles a declaration whose outcome is unknown after the operator checked it in the auto-entrepreneur space (ADR 0003):
 * either URSSAF holds it ({@see self::asDeclared()}, with its `certificat`) or it never received it
 * ({@see self::asNotReceived()}: the record is pending again, the host declares the turnover again).
 */
final readonly class ResolveDeclarationOutcome
{
    private function __construct(
        public TenantId $tenantId,
        public DeclarationSelector $declaration,
        public ?string $certificat,
    ) {}

    public static function asDeclared(TenantId $tenantId, DeclarationSelector $declaration, string $certificat): self
    {
        return new self($tenantId, $declaration, $certificat);
    }

    public static function asNotReceived(TenantId $tenantId, DeclarationSelector $declaration): self
    {
        return new self($tenantId, $declaration, null);
    }
}
