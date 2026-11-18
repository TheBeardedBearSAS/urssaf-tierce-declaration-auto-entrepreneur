<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic;

use Override;

/**
 * The tokens are kept in a store shared by every process that calls URSSAF (a host service, or a dedicated cache pool):
 * the shared "cache.app" pool is cleared with the application cache, which drops the tokens.
 */
final readonly class TokenStoreCheck implements InstallationCheck
{
    private const NAME = 'token store';
    private const SHARED_POOL = 'cache.app';

    /**
     * @param ?string $service   the "token_store.service" option
     * @param string  $cachePool the "token_store.cache_pool" option
     */
    public function __construct(private ?string $service, private string $cachePool) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        $result = match (true) {
            $this->service !== null => [CheckStatus::Ok, \sprintf('Service "%s".', $this->service)],
            $this->cachePool === self::SHARED_POOL => [CheckStatus::Warning, 'Cache pool "cache.app": declare a dedicated pool shared by every process, so that clearing the application cache does not drop the tokens (docs/symfony.md).'],
            default => [CheckStatus::Ok, \sprintf('Cache pool "%s": it must be shared by every process that calls URSSAF.', $this->cachePool)],
        };

        return [new CheckResult(self::NAME, '', $result[0], $result[1])];
    }
}
