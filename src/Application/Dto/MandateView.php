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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;

/**
 * Read model of a mandate (primitives only: no SIRET). Instants are ISO 8601 UTC strings.
 */
final readonly class MandateView
{
    private function __construct(
        public string $mandateId,
        public string $tenantId,
        public ?string $accountId,
        public string $origin,
        public string $state,
        public ?string $cancellationOrigin,
        public string $notifiedAt,
        public ?string $cancelledAt,
        public string $updatedAt,
    ) {}

    public static function fromMandate(Mandate $mandate): self
    {
        return new self(
            $mandate->id()->value(),
            $mandate->tenantId()->value(),
            $mandate->accountId()?->value(),
            $mandate->origin()->value,
            $mandate->state()->value,
            $mandate->cancellationOrigin()?->value,
            $mandate->notifiedAt()->toIso8601(),
            $mandate->cancelledAt()?->toIso8601(),
            $mandate->updatedAt()->toIso8601(),
        );
    }
}
