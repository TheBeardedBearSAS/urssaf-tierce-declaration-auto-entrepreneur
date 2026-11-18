<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;

/**
 * An operation that needs a local mandate was refused before any call (ADR 0011). The message and the parameters never
 * hold the SIRET (ADR 0010 item 5).
 */
final class MandateRequired extends DomainException
{
    public static function forOperation(Operation $operation): self
    {
        return new self(
            \sprintf('No active local mandate is known for the SIRET: method %s is refused locally (ADR 0011).', $operation->value),
            'mandate.required',
            ['operation' => $operation->value],
        );
    }
}
