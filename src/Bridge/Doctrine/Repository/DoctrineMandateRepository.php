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

use Doctrine\ORM\EntityManagerInterface;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\ActiveMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\MandateIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Doctrine ORM implementation of {@see MandateRepository} with the semantics of the in-memory one: at most one active
 * (non-cancelled) mandate per (tenant, SIRET), reported as {@see InvariantViolation} "mandate.duplicate_active"
 * (without the SIRET, ADR 0010) and enforced by the database through the key table of {@see ActiveMandate} (primary key
 * (tenant, SIRET)), rewritten with DBAL in the transaction of every save.
 *
 * The queries select the active mandates as the ones not in the state "cancelled" ({@see MandateState::isActive()}; a
 * test fails if a state is ever added). Lists are ordered as the port says (oldest notification first, ties by id).
 *
 * Unit of work: {@see self::save()} persists and flushes immediately (a flush writes every dirty managed entity), so a
 * save inside a {@see DoctrineTransactionManager} transaction is durable only when that transaction commits. Reads
 * return the managed instance, so unlike the in-memory repository a change is visible to every holder of the instance
 * before it is saved.
 *
 * Concurrency (see {@see AggregateStore}): a copy that another writer changed or erased since it was read, and an
 * instance that is not managed while its identifier is stored, are refused with "mandate.concurrent_modification",
 * before any flush.
 */
final readonly class DoctrineMandateRepository implements MandateRepository
{
    private const ENTITY = Mandate::class;

    private const BY_NOTIFICATION = ' ORDER BY m.timeline.notifiedAt ASC, m.id ASC';

    /** @var AggregateStore<Mandate> */
    private AggregateStore $store;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->store = new AggregateStore(
            $entityManager,
            self::ENTITY,
            MandateIdType::NAME,
            self::CONCURRENT_MODIFICATION,
            'The mandate was changed by another writer since it was read.',
        );
    }

    /**
     * @throws InvariantViolation "mandate.concurrent_modification" or "mandate.duplicate_active"
     */
    #[Override]
    public function save(Mandate $mandate): void
    {
        $this->store->save($mandate, $mandate->id(), $this->assertSingleActive(...), $this->replaceKey(...));
    }

    #[Override]
    public function get(MandateId $id): Mandate
    {
        return $this->store->find($id) ?? throw RecordNotFound::withIdentifier('mandate', $id->value());
    }

    #[Override]
    public function findActive(TenantId $tenantId, Siret $siret): ?Mandate
    {
        return Hydration::first(
            $this->entityManager
                ->createQuery(
                    'SELECT m FROM ' . self::ENTITY . ' m WHERE m.tenantId = :tenant AND m.siret = :siret'
                    . ' AND m.state <> :cancelled' . self::BY_NOTIFICATION,
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->setParameter('cancelled', MandateState::Cancelled)
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function findBySiret(TenantId $tenantId, Siret $siret): array
    {
        return Hydration::all(
            $this->entityManager
                ->createQuery(
                    'SELECT m FROM ' . self::ENTITY . ' m WHERE m.tenantId = :tenant AND m.siret = :siret'
                    . self::BY_NOTIFICATION,
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->toIterable(),
            self::ENTITY,
        );
    }

    /**
     * GDPR erasure: the mandate and its key row go in one transaction (nothing happens for an unknown mandate), so that
     * the SIRET can hold a new active mandate afterwards.
     */
    #[Override]
    public function delete(MandateId $id): void
    {
        $this->store->connection()->transactional(function () use ($id): void {
            $this->store->discard($id);
            $this->store->connection()->delete($this->keyTable(), ['mandate_id' => $id], ActiveMandate::TYPES);
        });
    }

    private function keyTable(): string
    {
        return $this->entityManager->getClassMetadata(ActiveMandate::class)->getTableName();
    }

    /**
     * Rewrites the key row of the mandate (delete and insert: no existence check, no driver-specific "affected rows"
     * semantics). A concurrent second active mandate of the SIRET fails on the primary key.
     */
    private function replaceKey(Mandate $mandate): void
    {
        $connection = $this->store->connection();
        $connection->delete($this->keyTable(), ['mandate_id' => $mandate->id()], ActiveMandate::TYPES);
        if (!$mandate->isActive()) {
            return;
        }
        $connection->insert($this->keyTable(), ActiveMandate::of($mandate)->row(), ActiveMandate::TYPES);
    }

    /**
     * @throws InvariantViolation "mandate.duplicate_active"
     */
    private function assertSingleActive(Mandate $mandate): void
    {
        if (!$mandate->isActive()) {
            return;
        }
        $others = $this->entityManager
            ->createQuery(
                'SELECT m.id FROM ' . self::ENTITY . ' m WHERE m.tenantId = :tenant AND m.siret = :siret'
                . ' AND m.id <> :id AND m.state <> :cancelled',
            )
            ->setParameter('tenant', $mandate->tenantId(), TenantIdType::NAME)
            ->setParameter('siret', $mandate->siret(), SiretType::NAME)
            ->setParameter('id', $mandate->id(), MandateIdType::NAME)
            ->setParameter('cancelled', MandateState::Cancelled)
            ->getScalarResult();
        if ($others !== []) {
            // The SIRET is personal data (ADR 0010): never in an exception.
            throw InvariantViolation::because(self::DUPLICATE_ACTIVE, 'The SIRET already has an active mandate.');
        }
    }
}
