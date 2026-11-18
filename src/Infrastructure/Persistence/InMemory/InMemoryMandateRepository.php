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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Process-local {@see MandateRepository} for tests and demos.
 *
 * Identity semantics: like a database, it stores a snapshot. {@see self::save()} copies the aggregate (without its
 * pending domain events, which are never persisted) and every read returns a new copy, so a change is only visible once
 * saved.
 *
 * Optimistic concurrency: see {@see VersionTokens}. Saving a copy another writer changed since it was read is refused
 * (a never-read instance is refused too when the identifier is already stored).
 */
final class InMemoryMandateRepository implements MandateRepository
{
    /** @var array<string, Mandate> stored snapshots by mandate id, in insertion order */
    private array $mandates = [];

    private readonly VersionTokens $versions;

    public function __construct()
    {
        $this->versions = new VersionTokens(
            MandateRepository::CONCURRENT_MODIFICATION,
            'The mandate was changed by another writer since it was read.',
        );
    }

    /**
     * Copies of every stored mandate, in insertion order (statistics, tests).
     *
     * @return list<Mandate>
     */
    public function all(): array
    {
        return Selection::of($this->mandates)->copies($this->adopt(...));
    }

    /**
     * @throws InvariantViolation "mandate.concurrent_modification" (saved or erased by another writer since this
     *                            instance was read) or "mandate.duplicate_active"
     */
    #[Override]
    public function save(Mandate $mandate): void
    {
        $id = $mandate->id()->value();
        $this->versions->assertCurrent($mandate, $id);
        $this->assertSingleActive($mandate);
        $snapshot = clone $mandate;
        $snapshot->releaseEvents();
        $this->mandates[$id] = $snapshot;
        $this->versions->saved($mandate, $id);
    }

    #[Override]
    public function get(MandateId $id): Mandate
    {
        $stored = $this->mandates[$id->value()] ?? throw RecordNotFound::withIdentifier('mandate', $id->value());

        return $this->versions->track(clone $stored, $id->value());
    }

    #[Override]
    public function findActive(TenantId $tenantId, Siret $siret): ?Mandate
    {
        return Selection::of($this->mandates)
            ->where(
                static fn(Mandate $mandate): bool => $mandate->isActive()
                    && $mandate->tenantId()->equals($tenantId)
                    && $mandate->siret()->equals($siret),
            )
            ->copies($this->adopt(...))[0] ?? null;
    }

    #[Override]
    public function findBySiret(TenantId $tenantId, Siret $siret): array
    {
        return Selection::of($this->mandates)
            ->where(
                static fn(Mandate $mandate): bool => $mandate->tenantId()->equals($tenantId)
                    && $mandate->siret()->equals($siret),
            )
            ->sortedBy(Selection::thenById(
                static fn(Mandate $a, Mandate $b): int => Selection::compareInstants($a->notifiedAt(), $b->notifiedAt()),
                static fn(Mandate $mandate): string => $mandate->id()->value(),
            ))
            ->copies($this->adopt(...));
    }

    #[Override]
    public function delete(MandateId $id): void
    {
        unset($this->mandates[$id->value()]);
        $this->versions->forget($id->value());
    }

    private function adopt(Mandate $copy): Mandate
    {
        return $this->versions->track($copy, $copy->id()->value());
    }

    /**
     * @throws InvariantViolation "mandate.duplicate_active": the SIRET already has another non-cancelled mandate
     */
    private function assertSingleActive(Mandate $mandate): void
    {
        if (!$mandate->isActive()) {
            return;
        }

        $others = Selection::of($this->mandates)
            ->where(
                static fn(Mandate $stored): bool => $stored->isActive()
                    && !$stored->id()->equals($mandate->id())
                    && $stored->tenantId()->equals($mandate->tenantId())
                    && $stored->siret()->equals($mandate->siret()),
            )
            ->all();
        if ($others !== []) {
            throw InvariantViolation::because(
                MandateRepository::DUPLICATE_ACTIVE,
                'The SIRET already has an active mandate.',
            );
        }
    }
}
