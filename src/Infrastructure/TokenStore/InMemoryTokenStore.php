<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\AccessToken;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;

/**
 * Token cache kept in process memory (lives as long as the store instance).
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, AccessToken> */
    private array $tokens = [];

    public function get(string $key): ?AccessToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function save(string $key, #[SensitiveParameter] AccessToken $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
