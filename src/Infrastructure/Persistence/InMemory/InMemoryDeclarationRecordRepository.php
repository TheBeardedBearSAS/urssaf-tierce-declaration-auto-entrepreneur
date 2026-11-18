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

use DateInterval;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Process-local {@see DeclarationRecordRepository} for tests and demos.
 *
 * Identity semantics: like a database, it stores a snapshot. {@see self::save()} copies the aggregate (without its
 * pending domain events, which are never persisted) and every read returns a new copy, so a change is only visible
 * once saved. Because the stored copy is private, {@see self::claimForSubmission()} can apply
 * {@see DeclarationRecord::startSubmission()} to it without touching the events pending on the caller's instance (the
 * transition records no event, as the port requires).
 *
 * Lists are ordered as documented by the port; ties are broken by id.
 *
 * Uniqueness is strict, like the key table of the Doctrine bridge: a current record refuses a save while another
 * record is current for the same (tenant, SIRET, period). On a supersession the caller therefore saves the superseded
 * record first (it is no longer current), then the new one.
 *
 * One method per query of the port plus the claim and erasure helpers, hence the method count and complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 *
 * Optimistic concurrency: see {@see VersionTokens}. Saving a copy another writer changed since it was read is refused
 * (a never-read instance is refused too when the identifier is already stored).
 */
final class InMemoryDeclarationRecordRepository implements DeclarationRecordRepository
{
    /** @var array<string, DeclarationRecord> stored snapshots by record id, in insertion order */
    private array $records = [];

    private readonly VersionTokens $versions;

    public function __construct()
    {
        $this->versions = new VersionTokens(
            DeclarationRecordRepository::CONCURRENT_MODIFICATION,
            'The declaration record was changed by another writer since it was read.',
        );
    }

    /**
     * Copies of every stored record, in insertion order (statistics, tests).
     *
     * @return list<DeclarationRecord>
     */
    public function all(): array
    {
        return Selection::of($this->records)->copies($this->adopt(...));
    }

    /**
     * @throws InvariantViolation "declaration_record.concurrent_modification" (saved, claimed or erased by another
     *                            writer since this instance was read) or "declaration_record.duplicate_current"
     */
    #[Override]
    public function save(DeclarationRecord $record): void
    {
        $id = $record->id()->value();
        $this->versions->assertCurrent($record, $id);
        $this->assertSingleCurrent($record);
        $snapshot = clone $record;
        $snapshot->releaseEvents();
        $this->records[$id] = $snapshot;
        $this->versions->saved($record, $id);
    }

    #[Override]
    public function get(DeclarationRecordId $id): DeclarationRecord
    {
        $stored = $this->records[$id->value()] ?? throw RecordNotFound::withIdentifier('declaration_record', $id->value());

        return $this->versions->track(clone $stored, $id->value());
    }

    #[Override]
    public function findCurrent(TenantId $tenantId, Siret $siret, DeclarationPeriod $period): ?DeclarationRecord
    {
        return Selection::of($this->records)
            ->where(
                static fn(DeclarationRecord $record): bool => $record->isCurrent()
                    && $record->tenantId()->equals($tenantId)
                    && $record->siret()->equals($siret)
                    && $record->period()->equals($period),
            )
            ->copies($this->adopt(...))[0] ?? null;
    }

    #[Override]
    public function findByState(TenantId $tenantId, DeclarationState $state, int $limit): array
    {
        return Selection::of($this->records)
            ->where(
                static fn(DeclarationRecord $record): bool => $record->tenantId()->equals($tenantId)
                    && $record->state() === $state,
            )
            ->sortedBy(Selection::thenById(
                static fn(DeclarationRecord $a, DeclarationRecord $b): int => Selection::compareInstants(
                    $a->updatedAt(),
                    $b->updatedAt(),
                ),
                static fn(DeclarationRecord $record): string => $record->id()->value(),
            ))
            ->limit($limit)
            ->copies($this->adopt(...));
    }

    #[Override]
    public function findStaleSubmissions(Instant $olderThan, int $limit): array
    {
        return Selection::of($this->records)
            ->where(
                static fn(DeclarationRecord $record): bool => $record->state() === DeclarationState::Submitting
                    && $record->status()->isLastAttemptStartedBefore($olderThan, new DateInterval('PT0S')),
            )
            ->sortedBy(Selection::thenById(
                static fn(DeclarationRecord $a, DeclarationRecord $b): int => Selection::compareInstants(
                    $a->lastAttemptAt(),
                    $b->lastAttemptAt(),
                ),
                static fn(DeclarationRecord $record): string => $record->id()->value(),
            ))
            ->limit($limit)
            ->copies($this->adopt(...));
    }

    #[Override]
    public function countByState(TenantId $tenantId): array
    {
        return $this->count(static fn(DeclarationRecord $record): bool => $record->tenantId()->equals($tenantId));
    }

    #[Override]
    public function countAcceptedAfterSupersession(TenantId $tenantId): int
    {
        return \count(
            Selection::of($this->records)
                ->where(
                    static fn(DeclarationRecord $record): bool => $record->tenantId()->equals($tenantId)
                        && $record->isAcceptedAfterSupersession(),
                )
                ->all(),
        );
    }

    #[Override]
    public function tenantsWithState(DeclarationState $state): array
    {
        return array_map(
            static fn(DeclarationRecord $record): TenantId => $record->tenantId(),
            Selection::of($this->records)
                ->where(static fn(DeclarationRecord $record): bool => $record->state() === $state)
                ->distinctBy(static fn(DeclarationRecord $record): string => $record->tenantId()->value())
                ->sortedBy(
                    static fn(DeclarationRecord $a, DeclarationRecord $b): int => strcmp(
                        $a->tenantId()->value(),
                        $b->tenantId()->value(),
                    ),
                )
                ->all(),
        );
    }

    #[Override]
    public function claimForSubmission(DeclarationRecordId $id, Instant $now): bool
    {
        $stored = $this->records[$id->value()] ?? null;
        if ($stored === null || !$stored->isCurrent() || $stored->state() !== DeclarationState::Pending) {
            return false;
        }

        $stored->startSubmission($now);
        $this->versions->bump($id->value());

        return true;
    }

    #[Override]
    public function countBySiret(TenantId $tenantId, Siret $siret): array
    {
        return $this->count(
            static fn(DeclarationRecord $record): bool => $record->tenantId()->equals($tenantId)
                && $record->siret()->equals($siret),
        );
    }

    #[Override]
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array
    {
        $selected = Selection::of($this->records)
            ->where(
                static fn(DeclarationRecord $record): bool => $record->tenantId()->equals($tenantId)
                    && $record->siret()->equals($siret)
                    && \in_array($record->state(), $states, true),
            )
            ->sortedBy(Selection::thenById(
                static fn(DeclarationRecord $a, DeclarationRecord $b): int => Selection::compareInstants(
                    $a->createdAt(),
                    $b->createdAt(),
                ),
                static fn(DeclarationRecord $record): string => $record->id()->value(),
            ))
            ->all();

        $ids = [];
        foreach ($selected as $record) {
            $ids[] = $record->id();
            unset($this->records[$record->id()->value()]);
            $this->versions->forget($record->id()->value());
        }

        return $ids;
    }

    private function adopt(DeclarationRecord $copy): DeclarationRecord
    {
        return $this->versions->track($copy, $copy->id()->value());
    }

    /**
     * @param callable(DeclarationRecord): bool $matches
     *
     * @return array<string, int> state value => count, every state present
     */
    private function count(callable $matches): array
    {
        $counts = array_fill_keys(array_map(static fn(DeclarationState $state): string => $state->value, DeclarationState::cases()), 0);
        foreach (Selection::of($this->records)->where($matches)->all() as $record) {
            ++$counts[$record->state()->value];
        }

        return $counts;
    }

    /**
     * @throws InvariantViolation "declaration_record.duplicate_current": another record is current for the same
     *                            (tenant, SIRET, period); the parameters never hold the SIRET (ADR 0010)
     */
    private function assertSingleCurrent(DeclarationRecord $record): void
    {
        if (!$record->isCurrent()) {
            return;
        }

        $others = Selection::of($this->records)
            ->where(
                static fn(DeclarationRecord $stored): bool => $stored->isCurrent()
                    && !$stored->id()->equals($record->id())
                    && $stored->tenantId()->equals($record->tenantId())
                    && $stored->siret()->equals($record->siret())
                    && $stored->period()->equals($record->period()),
            )
            ->all();
        if ($others !== []) {
            throw InvariantViolation::because(
                DeclarationRecordRepository::DUPLICATE_CURRENT,
                'A current declaration record already exists for this SIRET and period.',
            );
        }
    }
}
