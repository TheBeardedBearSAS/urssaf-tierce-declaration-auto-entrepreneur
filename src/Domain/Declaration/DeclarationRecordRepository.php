<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Port: persistence of {@see DeclarationRecord}. Uniqueness expected: the id, and one current record per
 * (tenant, SIRET, period) (ADR 0009, 0016).
 *
 * Scope of the queries. {@see self::findCurrent()} and {@see self::claimForSubmission()} only see current records (not
 * superseded). Every other query sees every record, current and superseded: a superseded record keeps its state (it can
 * be submitting with a call in flight, outcome_unknown, or receive a late acceptance) and an erasure must find and delete
 * all of them.
 *
 * One method per query or command of the use cases, hence the method count.
 *
 * @mago-expect lint:too-many-methods One method per query or command of the use cases.
 */
interface DeclarationRecordRepository
{
    /** Violation code of a save refused because another writer changed the record since it was read. */
    public const CONCURRENT_MODIFICATION = 'declaration_record.concurrent_modification';

    /** Violation code of a save refused because the (tenant, SIRET, period) already has a current record. */
    public const DUPLICATE_CURRENT = 'declaration_record.duplicate_current';

    /**
     * Inserts or updates. Domain events are not persisted: release and publish them separately.
     *
     * Optimistic concurrency (ADR 0009, ADR 0016 item 6): a copy that another writer changed (saved, claimed with
     * {@see self::claimForSubmission()} or erased) since it was read is refused. The copy is then stale: read the record again.
     *
     * @throws InvariantViolation "declaration_record.concurrent_modification" (see {@see self::CONCURRENT_MODIFICATION})
     *                            or "declaration_record.duplicate_current" (see {@see self::DUPLICATE_CURRENT}: a
     *                            concurrent first declaration of the same period)
     */
    public function save(DeclarationRecord $record): void;

    /**
     * @throws RecordNotFound "declaration_record.not_found"
     */
    public function get(DeclarationRecordId $id): DeclarationRecord;

    /**
     * The current (not superseded) record of the SIRET and period, if any (idempotency of DeclareTurnover).
     */
    public function findCurrent(TenantId $tenantId, Siret $siret, DeclarationPeriod $period): ?DeclarationRecord;

    /**
     * Least recently updated first, ties by id.
     *
     * @param positive-int $limit
     *
     * @return list<DeclarationRecord>
     */
    public function findByState(TenantId $tenantId, DeclarationState $state, int $limit): array;

    /**
     * Every tenant, state submitting, last attempt at or before $olderThan (= now - stale submission delay), oldest
     * attempt first.
     *
     * @param positive-int $limit
     *
     * @return list<DeclarationRecord>
     */
    public function findStaleSubmissions(Instant $olderThan, int $limit): array;

    /**
     * Number of records per state: state value => count, every state present (0 when none).
     *
     * @return array<string, int>
     */
    public function countByState(TenantId $tenantId): array;

    /**
     * Number of records declared after they were superseded (counted under `needingAttention()`, ADR 0009).
     */
    public function countAcceptedAfterSupersession(TenantId $tenantId): int;

    /**
     * Tenants having at least one record in the state (requeue after invalid credentials, schedulers).
     *
     * @return list<TenantId>
     */
    public function tenantsWithState(DeclarationState $state): array;

    /**
     * Atomic compare-and-set: a record that is pending AND current becomes submitting, one more attempt, last attempt
     * and update at now, version bumped, exactly like {@see DeclarationRecord::startSubmission()}. True when this call
     * won, false when the record is missing, superseded or not pending. No event is recorded: reload the record with
     * {@see self::get()}.
     */
    public function claimForSubmission(DeclarationRecordId $id, Instant $now): bool;

    /**
     * Number of records of the SIRET per state (ADR 0017): state value => count, every state present (0 when none), like
     * {@see self::countByState()} restricted to the SIRET.
     *
     * @return array<string, int>
     */
    public function countBySiret(TenantId $tenantId, Siret $siret): array;

    /**
     * GDPR erasure (ADR 0017): deletes the records of the SIRET whose state is one of $states, with their current-record
     * key rows, and returns their ids, oldest creation first (ties by id). A record whose state is not in $states is
     * kept (a submission claimed concurrently survives: the caller re-counts with {@see self::countBySiret()}). No event.
     *
     * @param non-empty-list<DeclarationState> $states
     *
     * @return list<DeclarationRecordId>
     */
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array;
}
