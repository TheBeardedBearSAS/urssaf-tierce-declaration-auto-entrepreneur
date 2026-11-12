<?php

declare(strict_types=1);

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf;

/**
 * Paths of the URSSAF API (relative to {@see Environment::baseUri()}). See docs/api-reference.md.
 */
final class UrssafEndpoints
{
    public const TOKEN_PATH = '/api/oauth/v1/token';
    public const TOKEN_SCOPE = 'tiersdecl.ae';
    public const API_BASE_PATH = '/tiersdecl/v1/ae';
}
