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

/**
 * What ForgetAccount erased: identifiers only (ADR 0010, ADR 0017). The account id is null when no account was tracked
 * for the SIRET.
 */
final readonly class AccountErasure
{
    /**
     * @param list<string> $mandateIds           oldest first
     * @param list<string> $declarationRecordIds oldest creation first
     * @param list<string> $paymentRecordIds     oldest creation first
     */
    public function __construct(
        public ?string $accountId,
        public array $mandateIds,
        public array $declarationRecordIds,
        public array $paymentRecordIds,
    ) {}
}
