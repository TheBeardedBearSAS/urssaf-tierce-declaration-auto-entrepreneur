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

use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * An in-memory lock store that remembers which locks were taken and with which ttl (the lock extends its expiration to its
 * ttl right after taking it), and how many locks were deleted (released).
 */
final class RecordingStore implements PersistingStoreInterface
{
    /** @var list<string> */
    private array $saved = [];

    /** @var list<array{string, float}> */
    private array $ttls = [];

    private int $deleted = 0;

    private readonly InMemoryStore $store;

    public function __construct()
    {
        $this->store = new InMemoryStore();
    }

    /**
     * @return list<string> the name of each lock taken
     */
    public function saved(): array
    {
        return $this->saved;
    }

    /**
     * @return list<array{string, float}> the name and the ttl in seconds of each lock whose expiration was extended
     */
    public function ttls(): array
    {
        return $this->ttls;
    }

    public function deleted(): int
    {
        return $this->deleted;
    }

    #[Override]
    public function save(Key $key): void
    {
        $this->saved[] = (string) $key;
        $this->store->save($key);
    }

    #[Override]
    public function delete(Key $key): void
    {
        ++$this->deleted;
        $this->store->delete($key);
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->ttls[] = [(string) $key, $ttl];
        $this->store->putOffExpiration($key, $ttl);
    }
}
