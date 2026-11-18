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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\CurrentDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\DeclarationRecordIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Doctrine ORM implementation of {@see DeclarationRecordRepository} with the semantics of the in-memory one: lists are
 * ordered as the port says (least recently updated first, oldest attempt first, oldest creation first, always ending
 * with the identifier), and "one current record per (tenant, SIRET, period)" is reported as {@see InvariantViolation}
 * "declaration_record.duplicate_current" (without the SIRET, ADR 0010) and enforced by the database through the key
 * table of {@see CurrentDeclaration}, rewritten with DBAL in the transaction of every save.
 *
 * Scope of the queries (port): only {@see self::findCurrent()} and {@see self::claimForSubmission()} see just the
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
 * "declaration_record.concurrent_modification", before any flush.
 *
 * Implements the repository port: one method per query of the port, plus the uniqueness, key row, claim and erasure
 * helpers, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final readonly class DoctrineDeclarationRecordRepository implements DeclarationRecordRepository
{
    private const ENTITY = DeclarationRecord::class;

    /** @var AggregateStore<DeclarationRecord> */
    private AggregateStore $store;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->store = new AggregateStore(
            $entityManager,
            self::ENTITY,
            DeclarationRecordIdType::NAME,
            self::CONCURRENT_MODIFICATION,
            'The declaration record was changed by another writer since it was read.',
        );
    }

    /**
     * @throws InvariantViolation "declaration_record.concurrent_modification" or "declaration_record.duplicate_current"
     */
    #[Override]
    public function save(DeclarationRecord $record): void
    {
        $this->store->save($record, $record->id(), $this->assertSingleCurrent(...), $this->replaceKey(...));
    }

    #[Override]
    public function get(DeclarationRecordId $id): DeclarationRecord
    {
        return $this->store->find($id) ?? throw RecordNotFound::withIdentifier('declaration_record', $id->value());
    }

    #[Override]
    public function findCurrent(TenantId $tenantId, Siret $siret, DeclarationPeriod $period): ?DeclarationRecord
    {
        return Hydration::first(
            $this->entityManager
                ->createQuery(
                    'SELECT r FROM ' . self::ENTITY . ' r WHERE r.tenantId = :tenant AND r.siret = :siret'
                    . ' AND r.periodicity = :periodicity AND r.periodYear = :year AND r.periodNumber = :number'
                    . ' AND r.supersededBy IS NULL ORDER BY r.createdAt ASC, r.id ASC',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->setParameter('periodicity', $period->periodicity())
                ->setParameter('year', $period->year())
                ->setParameter('number', DeclarationPeriods::numberOf($period))
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function findByState(TenantId $tenantId, DeclarationState $state, int $limit): array
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
                ->setParameter('submitting', DeclarationState::Submitting)
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
    public function countAcceptedAfterSupersession(TenantId $tenantId): int
    {
        return \intval(
            $this->entityManager
                ->createQuery(
                    'SELECT COUNT(r.id) FROM ' . self::ENTITY
                    . ' r WHERE r.tenantId = :tenant AND r.acceptedAfterSupersession = true',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->getSingleScalarResult(),
        );
    }

    #[Override]
    public function tenantsWithState(DeclarationState $state): array
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
     * Compare-and-set pending -> submitting in one UPDATE, like {@see DeclarationRecord::startSubmission()}, bumping
     * the version so that a copy read before the claim is refused at its next save. The key tables are not touched: a
     * claim changes neither the identity nor the supersession of the record.
     */
    #[Override]
    public function claimForSubmission(DeclarationRecordId $id, Instant $now): bool
    {
        $claimed = $this->entityManager
            ->createQuery(
                'UPDATE ' . self::ENTITY . ' r SET r.status.state = :submitting,'
                . ' r.status.attempts = r.status.attempts + 1, r.status.lastAttemptAt = :now,'
                . ' r.status.updatedAt = :now, r.status.outcomeUnknownReason = NULL, r.version = r.version + 1'
                . ' WHERE r.id = :id AND r.status.state = :pending AND r.supersededBy IS NULL',
            )
            ->setParameter('submitting', DeclarationState::Submitting)
            ->setParameter('pending', DeclarationState::Pending)
            ->setParameter('now', $now, InstantType::NAME)
            ->setParameter('id', $id, DeclarationRecordIdType::NAME)
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
            $values = array_map(static fn(DeclarationState $state): string => $state->value, $states);
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
            $ids = array_map(DeclarationRecordId::fromString(...), $selected);
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
        foreach (DeclarationState::cases() as $state) {
            $counts[$state->value] = 0;
        }
        $query = $this->entityManager->createQuery($dql)->setParameter('tenant', $tenantId, TenantIdType::NAME);
        if ($siret instanceof Siret) {
            $query->setParameter('siret', $siret, SiretType::NAME);
        }
        /** @var list<array{state: DeclarationState, total: int|string}> $rows */
        $rows = $query->getResult();
        foreach ($rows as $row) {
            $counts[$row['state']->value] = \intval($row['total']);
        }

        return $counts;
    }

    private function keyTable(): string
    {
        return $this->entityManager->getClassMetadata(CurrentDeclaration::class)->getTableName();
    }

    /**
     * Rewrites the key row of the record (delete and insert: no existence check, no driver-specific "affected rows"
     * semantics). A concurrent current record of the same period fails on the primary key.
     */
    private function replaceKey(DeclarationRecord $record): void
    {
        $connection = $this->store->connection();
        $connection->delete($this->keyTable(), ['declaration_record_id' => $record->id()], CurrentDeclaration::TYPES);
        if (!$record->isCurrent()) {
            return;
        }
        $connection->insert($this->keyTable(), CurrentDeclaration::of($record)->row(), CurrentDeclaration::TYPES);
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
                'DELETE FROM %1$s WHERE declaration_record_id IN (:ids)'
                . ' AND declaration_record_id NOT IN (SELECT id FROM %2$s WHERE id IN (:ids))',
                $this->keyTable(),
                $recordTable,
            ),
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * @throws InvariantViolation "declaration_record.duplicate_current"
     */
    private function assertSingleCurrent(DeclarationRecord $record): void
    {
        if (!$record->isCurrent()) {
            return;
        }
        $others = $this->entityManager
            ->createQuery(
                \sprintf(
                    'SELECT r.id FROM %s r WHERE r.tenantId = :tenant AND r.siret = :siret
                    AND r.periodicity = :periodicity AND r.periodYear = :year AND r.periodNumber = :number
                    AND r.id <> :id AND r.supersededBy IS NULL',
                    self::ENTITY,
                ),
            )
            ->setParameter('tenant', $record->tenantId(), TenantIdType::NAME)
            ->setParameter('siret', $record->siret(), SiretType::NAME)
            ->setParameter('periodicity', $record->periodicity())
            ->setParameter('year', $record->period()->year())
            ->setParameter('number', DeclarationPeriods::numberOf($record->period()))
            ->setParameter('id', $record->id(), DeclarationRecordIdType::NAME)
            ->getScalarResult();
        if ($others !== []) {
            // The SIRET is personal data (ADR 0010): never in an exception.
            throw InvariantViolation::because(
                self::DUPLICATE_CURRENT,
                'A current declaration record already exists for this SIRET and period.',
            );
        }
    }
}
