<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Process-local {@see AeAccountRepository} for tests and demos.
 *
 * Identity semantics: like a database, it stores a snapshot. {@see self::save()} copies the aggregate (without its
 * pending domain events, which are never persisted) and every read returns a new copy, so a change is only visible once
 * saved.
 *
 * Optimistic concurrency: see {@see VersionTokens}. Saving a copy another writer changed since it was read is refused
 * (a never-read instance is refused too when the identifier is already stored).
 */
final class InMemoryAeAccountRepository implements AeAccountRepository
{
    /** @var array<string, AeAccount> stored snapshots by account id, in insertion order */
    private array $accounts = [];

    private readonly VersionTokens $versions;

    public function __construct()
    {
        $this->versions = new VersionTokens(
            AeAccountRepository::CONCURRENT_MODIFICATION,
            'The account was changed by another writer since it was read.',
        );
    }

    /**
     * Copies of every stored account, in insertion order (statistics, tests).
     *
     * @return list<AeAccount>
     */
    public function all(): array
    {
        return Selection::of($this->accounts)->copies($this->adopt(...));
    }

    /**
     * @throws InvariantViolation "ae_account.concurrent_modification" (saved or erased by another writer since this
     *                            instance was read) or "ae_account.duplicate_siret"
     */
    #[Override]
    public function save(AeAccount $account): void
    {
        $id = $account->id()->value();
        $this->versions->assertCurrent($account, $id);
        $this->assertUniqueSiret($account);
        $snapshot = clone $account;
        $snapshot->releaseEvents();
        $this->accounts[$id] = $snapshot;
        $this->versions->saved($account, $id);
    }

    #[Override]
    public function get(AccountId $id): AeAccount
    {
        $stored = $this->accounts[$id->value()] ?? throw RecordNotFound::withIdentifier('ae_account', $id->value());

        return $this->versions->track(clone $stored, $id->value());
    }

    #[Override]
    public function findBySiret(TenantId $tenantId, Siret $siret): ?AeAccount
    {
        return Selection::of($this->accounts)
            ->where(
                static fn(AeAccount $account): bool => $account->tenantId()->equals($tenantId)
                    && $account->siret()->equals($siret),
            )
            ->copies($this->adopt(...))[0] ?? null;
    }

    #[Override]
    public function findLeastRecentlySynced(TenantId $tenantId, int $limit): array
    {
        return Selection::of($this->accounts)
            ->where(static fn(AeAccount $account): bool => $account->tenantId()->equals($tenantId))
            ->sortedBy(Selection::thenById(
                static fn(AeAccount $a, AeAccount $b): int => Selection::compareInstants($a->lastSyncedAt(), $b->lastSyncedAt()),
                static fn(AeAccount $account): string => $account->id()->value(),
            ))
            ->limit($limit)
            ->copies($this->adopt(...));
    }

    #[Override]
    public function tenants(): array
    {
        return array_map(
            static fn(AeAccount $account): TenantId => $account->tenantId(),
            Selection::of($this->accounts)
                ->distinctBy(static fn(AeAccount $account): string => $account->tenantId()->value())
                ->sortedBy(
                    static fn(AeAccount $a, AeAccount $b): int => strcmp($a->tenantId()->value(), $b->tenantId()->value()),
                )
                ->all(),
        );
    }

    #[Override]
    public function delete(AccountId $id): void
    {
        unset($this->accounts[$id->value()]);
        $this->versions->forget($id->value());
    }

    private function adopt(AeAccount $copy): AeAccount
    {
        return $this->versions->track($copy, $copy->id()->value());
    }

    /**
     * @throws InvariantViolation "ae_account.duplicate_siret": the message and the parameters never hold the SIRET (ADR 0010)
     */
    private function assertUniqueSiret(AeAccount $account): void
    {
        $others = Selection::of($this->accounts)
            ->where(
                static fn(AeAccount $stored): bool => !$stored->id()->equals($account->id())
                    && $stored->tenantId()->equals($account->tenantId())
                    && $stored->siret()->equals($account->siret()),
            )
            ->all();
        if ($others !== []) {
            throw InvariantViolation::because(
                AeAccountRepository::DUPLICATE_SIRET,
                'The tenant already tracks an account for this SIRET.',
            );
        }
    }
}
