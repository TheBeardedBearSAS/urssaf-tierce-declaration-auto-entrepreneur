<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\CurrentPayment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\PaymentRecordIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\PaymentReferenceType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Doctrine ORM implementation of {@see PaymentRecordRepository} with the semantics of the in-memory one: lists are
 * ordered as the port says (least recently updated first, oldest attempt first, oldest creation first, always ending
 * with the identifier), and "one current record per (tenant, payment reference)" is reported as
 * {@see InvariantViolation} "payment_record.duplicate_reference" (without the reference) and enforced by the database
 * through the key table of {@see CurrentPayment}, rewritten with DBAL in the transaction of every save.
 *
 * Scope of the queries (port): only {@see self::findByReference()}, {@see self::findCurrentForPeriod()} and {@see self::claimForSubmission()} see just the
 * current records (not superseded); every other query sees every record.
 *
 * Unit of work: {@see self::save()} persists and flushes immediately (a flush writes every dirty managed entity), so a
 * save inside a {@see DoctrineTransactionManager} transaction is durable only when that transaction commits: a
 * supersession (the superseded record saved first, then the new one) inside one transaction is atomic. Reads return the
 * managed instance, so unlike the in-memory repository a change is visible to every holder of the instance before it
 * is saved.
 *
 * Claiming: {@see self::claimForSubmission()} is one conditional UPDATE that also bumps the version column, never an
 * object round-trip. Concurrency (see {@see AggregateStore}): a copy that another writer changed, claimed or erased
 * since it was read, and an instance that is not managed while its identifier is stored, are refused with
 * "payment_record.concurrent_modification", before any flush.
 *
 * Implements the repository port: one method per query of the port, plus the uniqueness, key row, claim and erasure
 * helpers, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final readonly class DoctrinePaymentRecordRepository implements PaymentRecordRepository
{
    private const ENTITY = PaymentRecord::class;

    /** @var AggregateStore<PaymentRecord> */
    private AggregateStore $store;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->store = new AggregateStore(
            $entityManager,
            self::ENTITY,
            PaymentRecordIdType::NAME,
            self::CONCURRENT_MODIFICATION,
            'The payment record was changed by another writer since it was read.',
        );
    }

    /**
     * @throws InvariantViolation "payment_record.concurrent_modification" or "payment_record.duplicate_reference"
     */
    #[Override]
    public function save(PaymentRecord $record): void
    {
        $this->store->save($record, $record->id(), $this->assertSingleCurrent(...), $this->replaceKey(...));
    }

    #[Override]
    public function get(PaymentRecordId $id): PaymentRecord
    {
        return $this->store->find($id) ?? throw RecordNotFound::withIdentifier('payment_record', $id->value());
    }

    #[Override]
    public function findByReference(TenantId $tenantId, PaymentReference $reference): ?PaymentRecord
    {
        return Hydration::first(
            $this->entityManager
                ->createQuery(
                    'SELECT r FROM ' . self::ENTITY . ' r WHERE r.tenantId = :tenant AND r.reference = :reference'
                    . ' AND r.supersededBy IS NULL ORDER BY r.createdAt ASC, r.id ASC',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('reference', $reference, PaymentReferenceType::NAME)
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function findCurrentForPeriod(TenantId $tenantId, Siret $siret, DeclarationPeriod $period, array $states): array
    {
        return Hydration::all(
            $this->entityManager
                ->createQuery(
                    \sprintf(
                        'SELECT r FROM %s r WHERE r.tenantId = :tenant AND r.siret = :siret
                        AND r.periodicity = :periodicity AND r.periodYear = :year AND r.periodNumber = :number
                        AND r.supersededBy IS NULL AND r.status.state IN (:states)
                        ORDER BY r.createdAt ASC, r.id ASC',
                        self::ENTITY,
                    ),
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->setParameter('periodicity', $period->periodicity())
                ->setParameter('year', $period->year())
                ->setParameter('number', DeclarationPeriods::numberOf($period))
                ->setParameter(
                    'states',
                    array_map(static fn(PaymentState $state): string => $state->value, $states),
                    ArrayParameterType::STRING,
                )
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function findByState(TenantId $tenantId, PaymentState $state, int $limit): array
    {
        return Hydration::all(
            $this->entityManager
                ->createQuery(
                    'SELECT r FROM ' . self::ENTITY . ' r WHERE r.tenantId = :tenant AND r.status.state = :state'
                    . ' ORDER BY r.status.updatedAt ASC, r.id ASC',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('state', $state)
                ->setMaxResults($limit)
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function findStaleSubmissions(Instant $olderThan, int $limit): array
    {
        return Hydration::all(
            $this->entityManager
                ->createQuery(
                    'SELECT r FROM ' . self::ENTITY . ' r WHERE r.status.state = :submitting'
                    . ' AND r.status.lastAttemptAt <= :olderThan'
                    . ' ORDER BY r.status.lastAttemptAt ASC, r.id ASC',
                )
                ->setParameter('submitting', PaymentState::Submitting)
                ->setParameter('olderThan', $olderThan, InstantType::NAME)
                ->setMaxResults($limit)
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function countByState(TenantId $tenantId): array
    {
        return $this->counts(
            'SELECT r.status.state AS state, COUNT(r.id) AS total FROM ' . self::ENTITY
            . ' r WHERE r.tenantId = :tenant GROUP BY r.status.state',
            $tenantId,
            null,
        );
    }

    #[Override]
    public function tenantsWithState(PaymentState $state): array
    {
        /** @var list<string> $tenants */
        $tenants = $this->entityManager
            ->createQuery(
                'SELECT DISTINCT r.tenantId FROM ' . self::ENTITY
                . ' r WHERE r.status.state = :state ORDER BY r.tenantId ASC',
            )
            ->setParameter('state', $state)
            ->getSingleColumnResult();

        return array_map(TenantId::fromString(...), $tenants);
    }

    /**
     * Compare-and-set pending -> submitting in one UPDATE, like {@see PaymentRecord::startSubmission()}, bumping
     * the version so that a copy read before the claim is refused at its next save. The key tables are not touched: a
     * claim changes neither the identity nor the supersession of the record.
     */
    #[Override]
    public function claimForSubmission(PaymentRecordId $id, Instant $now): bool
    {
        $claimed = $this->entityManager
            ->createQuery(
                'UPDATE ' . self::ENTITY . ' r SET r.status.state = :submitting,'
                . ' r.status.attempts = r.status.attempts + 1, r.status.lastAttemptAt = :now,'
                . ' r.status.updatedAt = :now, r.status.outcomeUnknownReason = NULL, r.version = r.version + 1'
                . ' WHERE r.id = :id AND r.status.state = :pending AND r.supersededBy IS NULL',
            )
            ->setParameter('submitting', PaymentState::Submitting)
            ->setParameter('pending', PaymentState::Pending)
            ->setParameter('now', $now, InstantType::NAME)
            ->setParameter('id', $id, PaymentRecordIdType::NAME)
            ->execute() === 1;
        if ($claimed) {
            // The managed copy (if any) is now stale: drop it so that the next get() reads the claimed row.
            $this->store->detach($id);
        }

        return $claimed;
    }

    #[Override]
    public function countBySiret(TenantId $tenantId, Siret $siret): array
    {
        return $this->counts(
            'SELECT r.status.state AS state, COUNT(r.id) AS total FROM ' . self::ENTITY
            . ' r WHERE r.tenantId = :tenant AND r.siret = :siret GROUP BY r.status.state',
            $tenantId,
            $siret,
        );
    }

    /**
     * Selects the ids first (to report and detach them), then deletes under the same condition as the SELECT, state
     * included: a record claimed by another writer between the two statements is no longer in $states and survives
     * (with its key row), so that the caller's re-count sees it. The key rows of the records that are gone follow.
     */
    #[Override]
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array
    {
        return $this->store->connection()->transactional(function () use ($tenantId, $siret, $states): array {
            $values = array_map(static fn(PaymentState $state): string => $state->value, $states);
            /** @var list<string> $selected */
            $selected = $this->entityManager
                ->createQuery(
                    'SELECT r.id FROM ' . self::ENTITY . ' r WHERE r.tenantId = :tenant AND r.siret = :siret'
                    . ' AND r.status.state IN (:states) ORDER BY r.createdAt ASC, r.id ASC',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->setParameter('states', $values, ArrayParameterType::STRING)
                ->getSingleColumnResult();
            if ($selected === []) {
                return [];
            }
            $ids = array_map(PaymentRecordId::fromString(...), $selected);
            foreach ($ids as $id) {
                $this->store->detach($id);
            }
            $this->entityManager
                ->createQuery(
                    'DELETE FROM ' . self::ENTITY . ' r WHERE r.tenantId = :tenant AND r.siret = :siret'
                    . ' AND r.status.state IN (:states)',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->setParameter('states', $values, ArrayParameterType::STRING)
                ->execute();
            $this->deleteKeysOfErasedRecords($selected);

            return $ids;
        });
    }

    /**
     * Number of records per state, every state present (0 when none).
     *
     * @return array<string, int>
     */
    private function counts(string $dql, TenantId $tenantId, ?Siret $siret): array
    {
        $counts = [];
        foreach (PaymentState::cases() as $state) {
            $counts[$state->value] = 0;
        }
        $query = $this->entityManager->createQuery($dql)->setParameter('tenant', $tenantId, TenantIdType::NAME);
        if ($siret instanceof Siret) {
            $query->setParameter('siret', $siret, SiretType::NAME);
        }
        /** @var list<array{state: PaymentState, total: int|string}> $rows */
        $rows = $query->getResult();
        foreach ($rows as $row) {
            $counts[$row['state']->value] = \intval($row['total']);
        }

        return $counts;
    }

    private function keyTable(): string
    {
        return $this->entityManager->getClassMetadata(CurrentPayment::class)->getTableName();
    }

    /**
     * Rewrites the key row of the record (delete and insert: no existence check, no driver-specific "affected rows"
     * semantics). A concurrent current record of the same payment reference fails on the primary key.
     */
    private function replaceKey(PaymentRecord $record): void
    {
        $connection = $this->store->connection();
        $connection->delete($this->keyTable(), ['payment_record_id' => $record->id()], CurrentPayment::TYPES);
        if (!$record->isCurrent()) {
            return;
        }
        $connection->insert($this->keyTable(), CurrentPayment::of($record)->row(), CurrentPayment::TYPES);
    }

    /**
     * Removes the key rows of the erased records that are really gone: a record that survived the DELETE (claimed in
     * between) keeps its key row. No self-referencing subquery, so the statement is portable.
     *
     * @param non-empty-list<string> $ids
     */
    private function deleteKeysOfErasedRecords(array $ids): void
    {
        $recordTable = $this->entityManager->getClassMetadata(self::ENTITY)->getTableName();
        $this->store->connection()->executeStatement(
            \sprintf(
                'DELETE FROM %1$s WHERE payment_record_id IN (:ids)'
                . ' AND payment_record_id NOT IN (SELECT id FROM %2$s WHERE id IN (:ids))',
                $this->keyTable(),
                $recordTable,
            ),
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * @throws InvariantViolation "payment_record.duplicate_reference"
     */
    private function assertSingleCurrent(PaymentRecord $record): void
    {
        if (!$record->isCurrent()) {
            return;
        }
        $others = $this->entityManager
            ->createQuery(
                'SELECT r.id FROM ' . self::ENTITY . ' r WHERE r.tenantId = :tenant AND r.reference = :reference'
                . ' AND r.id <> :id AND r.supersededBy IS NULL',
            )
            ->setParameter('tenant', $record->tenantId(), TenantIdType::NAME)
            ->setParameter('reference', $record->reference(), PaymentReferenceType::NAME)
            ->setParameter('id', $record->id(), PaymentRecordIdType::NAME)
            ->getScalarResult();
        if ($others !== []) {
            // No identifier of the record in the violation (the in-memory adapter has none either).
            throw InvariantViolation::because(
                self::DUPLICATE_REFERENCE,
                'A current payment record already exists for this payment reference.',
            );
        }
    }
}
