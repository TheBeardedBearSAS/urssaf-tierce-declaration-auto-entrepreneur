<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

/**
 * How URSSAF framed a 040, 050 or 060 answer (ADR 0006): the production gateway wraps the object under a key
 * (`OutputDeclaration`, `OutputTelepaiement`), the sandbox and the contract answer it directly.
 */
enum ResponseShape: string
{
    case Wrapped = 'wrapped';
    case Direct = 'direct';
}
