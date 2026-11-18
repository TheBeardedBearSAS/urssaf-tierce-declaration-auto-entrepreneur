<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Port: persistence of {@see PaymentRecord}. Uniqueness expected: the id, and one current record per
 * (tenant, payment reference) (ADR 0009, 0016).
 *
 * Scope of the queries. {@see self::findByReference()}, {@see self::findCurrentForPeriod()} and
 * {@see self::claimForSubmission()} only see current records (not superseded). Every other query sees every record,
 * current and superseded: a superseded record keeps its state and an erasure must find and delete all of them.
 *
 * One method per query or command of the use cases, hence the method count.
 *
 * @mago-expect lint:too-many-methods One method per query or command of the use cases.
 */
interface PaymentRecordRepository
{
    /** Violation code of a save refused because another writer changed the record since it was read. */
    public const CONCURRENT_MODIFICATION = 'payment_record.concurrent_modification';

    /** Violation code of a save refused because the (tenant, payment reference) already has a current record. */
    public const DUPLICATE_REFERENCE = 'payment_record.duplicate_reference';

    /**
     * Inserts or updates. Domain events are not persisted: release and publish them separately.
     *
     * Optimistic concurrency (ADR 0009, ADR 0016 item 6): a copy that another writer changed (saved, claimed with
     * {@see self::claimForSubmission()} or erased) since it was read is refused. The copy is then stale: read the record again.
     *
     * @throws InvariantViolation "payment_record.concurrent_modification" (see {@see self::CONCURRENT_MODIFICATION})
     *                            or "payment_record.duplicate_reference" (see {@see self::DUPLICATE_REFERENCE}: a
     *                            concurrent first payment of the same reference)
     */
    public function save(PaymentRecord $record): void;

    /**
     * @throws RecordNotFound "payment_record.not_found"
     */
    public function get(PaymentRecordId $id): PaymentRecord;

    /**
     * The current (not superseded) record of the payment reference, if any (idempotency of PayContributions).
     */
    public function findByReference(TenantId $tenantId, PaymentReference $reference): ?PaymentRecord;

    /**
     * Current records of the SIRET and period whose state is one of $states, oldest creation first (second-payment
     * guard, ADR 0011 item 3: submitting, paid, outcome_unknown).
     *
     * @param non-empty-list<PaymentState> $states
     *
     * @return list<PaymentRecord>
     */
    public function findCurrentForPeriod(TenantId $tenantId, Siret $siret, DeclarationPeriod $period, array $states): array;

    /**
     * Least recently updated first, ties by id.
     *
     * @param positive-int $limit
     *
     * @return list<PaymentRecord>
     */
    public function findByState(TenantId $tenantId, PaymentState $state, int $limit): array;

    /**
     * Every tenant, state submitting, last attempt at or before $olderThan (= now - stale submission delay), oldest
     * attempt first.
     *
     * @param positive-int $limit
     *
     * @return list<PaymentRecord>
     */
    public function findStaleSubmissions(Instant $olderThan, int $limit): array;

    /**
     * Number of records per state: state value => count, every state present (0 when none).
     *
     * @return array<string, int>
     */
    public function countByState(TenantId $tenantId): array;

    /**
     * Tenants having at least one record in the state (requeue after invalid credentials, schedulers).
     *
     * @return list<TenantId>
     */
    public function tenantsWithState(PaymentState $state): array;

    /**
     * Atomic compare-and-set: a record that is pending AND current becomes submitting, one more attempt, last attempt
     * and update at now, version bumped, exactly like {@see PaymentRecord::startSubmission()}. True when this call won,
     * false when the record is missing, superseded or not pending. No event is recorded: reload the record with
     * {@see self::get()}.
     */
    public function claimForSubmission(PaymentRecordId $id, Instant $now): bool;

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
     * @param non-empty-list<PaymentState> $states
     *
     * @return list<PaymentRecordId>
     */
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array;
}
