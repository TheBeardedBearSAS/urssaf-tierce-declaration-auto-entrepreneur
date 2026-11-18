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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Process-local {@see PaymentRecordRepository} for tests and demos.
 *
 * Identity semantics: like a database, it stores a snapshot. {@see self::save()} copies the aggregate (without its
 * pending domain events, which are never persisted) and every read returns a new copy, so a change is only visible
 * once saved. Because the stored copy is private, {@see self::claimForSubmission()} can apply
 * {@see PaymentRecord::startSubmission()} to it without touching the events pending on the caller's instance (the
 * transition records no event, as the port requires).
 *
 * Lists are ordered as documented by the port; ties are broken by id.
 *
 * Uniqueness is strict, like the key table of the Doctrine bridge: a current record refuses a save while another
 * record is current for the same (tenant, payment reference). On a supersession the caller therefore saves the
 * superseded record first (it is no longer current), then the new one.
 *
 * One method per query of the port plus the claim and erasure helpers, hence the method count and complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 *
 * Optimistic concurrency: see {@see VersionTokens}. Saving a copy another writer changed since it was read is refused
 * (a never-read instance is refused too when the identifier is already stored).
 */
final class InMemoryPaymentRecordRepository implements PaymentRecordRepository
{
    /** @var array<string, PaymentRecord> stored snapshots by record id, in insertion order */
    private array $records = [];

    private readonly VersionTokens $versions;

    public function __construct()
    {
        $this->versions = new VersionTokens(
            PaymentRecordRepository::CONCURRENT_MODIFICATION,
            'The payment record was changed by another writer since it was read.',
        );
    }

    /**
     * Copies of every stored record, in insertion order (statistics, tests).
     *
     * @return list<PaymentRecord>
     */
    public function all(): array
    {
        return Selection::of($this->records)->copies(fn(PaymentRecord $copy): PaymentRecord => $this->adopt($copy));
    }

    /**
     * @throws InvariantViolation "payment_record.concurrent_modification" (saved, claimed or erased by another writer
     *                            since this instance was read) or "payment_record.duplicate_reference"
     */
    #[Override]
    public function save(PaymentRecord $record): void
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
    public function get(PaymentRecordId $id): PaymentRecord
    {
        $stored = $this->records[$id->value()] ?? throw RecordNotFound::withIdentifier('payment_record', $id->value());

        return $this->versions->track(clone $stored, $id->value());
    }

    #[Override]
    public function findByReference(TenantId $tenantId, PaymentReference $reference): ?PaymentRecord
    {
        return Selection::of($this->records)
            ->where(
                static fn(PaymentRecord $record): bool => $record->isCurrent()
                    && $record->tenantId()->equals($tenantId)
                    && $record->reference()->equals($reference),
            )
            ->copies(fn(PaymentRecord $copy): PaymentRecord => $this->adopt($copy))[0] ?? null;
    }

    #[Override]
    public function findCurrentForPeriod(TenantId $tenantId, Siret $siret, DeclarationPeriod $period, array $states): array
    {
        return Selection::of($this->records)
            ->where(
                static fn(PaymentRecord $record): bool => $record->isCurrent()
                    && $record->tenantId()->equals($tenantId)
                    && $record->siret()->equals($siret)
                    && $record->period()->equals($period)
                    && \in_array($record->state(), $states, true),
            )
            ->sortedBy(Selection::thenById(
                static fn(PaymentRecord $a, PaymentRecord $b): int => Selection::compareInstants(
                    $a->createdAt(),
                    $b->createdAt(),
                ),
                static fn(PaymentRecord $record): string => $record->id()->value(),
            ))
            ->copies(fn(PaymentRecord $copy): PaymentRecord => $this->adopt($copy));
    }

    #[Override]
    public function findByState(TenantId $tenantId, PaymentState $state, int $limit): array
    {
        return Selection::of($this->records)
            ->where(
                static fn(PaymentRecord $record): bool => $record->tenantId()->equals($tenantId)
                    && $record->state() === $state,
            )
            ->sortedBy(Selection::thenById(
                static fn(PaymentRecord $a, PaymentRecord $b): int => Selection::compareInstants(
                    $a->updatedAt(),
                    $b->updatedAt(),
                ),
                static fn(PaymentRecord $record): string => $record->id()->value(),
            ))
            ->limit($limit)
            ->copies(fn(PaymentRecord $copy): PaymentRecord => $this->adopt($copy));
    }

    #[Override]
    public function findStaleSubmissions(Instant $olderThan, int $limit): array
    {
        return Selection::of($this->records)
            ->where(
                static fn(PaymentRecord $record): bool => $record->state() === PaymentState::Submitting
                    && $record->status()->isLastAttemptStartedBefore($olderThan, new DateInterval('PT0S')),
            )
            ->sortedBy(Selection::thenById(
                static fn(PaymentRecord $a, PaymentRecord $b): int => Selection::compareInstants(
                    $a->lastAttemptAt(),
                    $b->lastAttemptAt(),
                ),
                static fn(PaymentRecord $record): string => $record->id()->value(),
            ))
            ->limit($limit)
            ->copies(fn(PaymentRecord $copy): PaymentRecord => $this->adopt($copy));
    }

    #[Override]
    public function countByState(TenantId $tenantId): array
    {
        return $this->count(static fn(PaymentRecord $record): bool => $record->tenantId()->equals($tenantId));
    }

    #[Override]
    public function tenantsWithState(PaymentState $state): array
    {
        return array_map(
            static fn(PaymentRecord $record): TenantId => $record->tenantId(),
            Selection::of($this->records)
                ->where(static fn(PaymentRecord $record): bool => $record->state() === $state)
                ->distinctBy(static fn(PaymentRecord $record): string => $record->tenantId()->value())
                ->sortedBy(
                    static fn(PaymentRecord $a, PaymentRecord $b): int => strcmp(
                        $a->tenantId()->value(),
                        $b->tenantId()->value(),
                    ),
                )
                ->all(),
        );
    }

    #[Override]
    public function claimForSubmission(PaymentRecordId $id, Instant $now): bool
    {
        $stored = $this->records[$id->value()] ?? null;
        if ($stored === null || !$stored->isCurrent() || $stored->state() !== PaymentState::Pending) {
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
            static fn(PaymentRecord $record): bool => $record->tenantId()->equals($tenantId)
                && $record->siret()->equals($siret),
        );
    }

    #[Override]
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array
    {
        $selected = Selection::of($this->records)
            ->where(
                static fn(PaymentRecord $record): bool => $record->tenantId()->equals($tenantId)
                    && $record->siret()->equals($siret)
                    && \in_array($record->state(), $states, true),
            )
            ->sortedBy(Selection::thenById(
                static fn(PaymentRecord $a, PaymentRecord $b): int => Selection::compareInstants(
                    $a->createdAt(),
                    $b->createdAt(),
                ),
                static fn(PaymentRecord $record): string => $record->id()->value(),
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

    private function adopt(PaymentRecord $copy): PaymentRecord
    {
        return $this->versions->track($copy, $copy->id()->value());
    }

    /**
     * @param callable(PaymentRecord): bool $matches
     *
     * @return array<string, int> state value => count, every state present
     */
    private function count(callable $matches): array
    {
        $counts = array_fill_keys(array_map(static fn(PaymentState $state): string => $state->value, PaymentState::cases()), 0);
        foreach (Selection::of($this->records)->where($matches)->all() as $record) {
            ++$counts[$record->state()->value];
        }

        return $counts;
    }

    /**
     * @throws InvariantViolation "payment_record.duplicate_reference": another record is current for the same
     *                            (tenant, payment reference)
     */
    private function assertSingleCurrent(PaymentRecord $record): void
    {
        if (!$record->isCurrent()) {
            return;
        }

        $others = Selection::of($this->records)
            ->where(
                static fn(PaymentRecord $stored): bool => $stored->isCurrent()
                    && !$stored->id()->equals($record->id())
                    && $stored->tenantId()->equals($record->tenantId())
                    && $stored->reference()->equals($record->reference()),
            )
            ->all();
        if ($others !== []) {
            throw InvariantViolation::because(
                PaymentRecordRepository::DUPLICATE_REFERENCE,
                'A current payment record already exists for this payment reference.',
            );
        }
    }
}
