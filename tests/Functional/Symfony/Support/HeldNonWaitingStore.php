<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support;

use LogicException;
use Override;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;

/**
 * A lock store that is always held by someone else and that refuses to wait: a blocking acquisition fails at once instead of
 * polling forever, so a lock taken with `acquire(true)` makes the test fail fast (never time out).
 */
final class HeldNonWaitingStore implements BlockingStoreInterface
{
    #[Override]
    public function save(Key $key): void
    {
        throw new LockConflictedException();
    }

    #[Override]
    public function waitAndSave(Key $key): void
    {
        throw new LogicException('The lock must never be acquired in blocking mode.');
    }

    #[Override]
    public function delete(Key $key): void {}

    #[Override]
    public function exists(Key $key): bool
    {
        return false;
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void {}
}
