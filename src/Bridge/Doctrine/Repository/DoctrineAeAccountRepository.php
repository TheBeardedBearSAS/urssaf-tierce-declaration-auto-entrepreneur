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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\AccountIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Doctrine ORM implementation of {@see AeAccountRepository} with the semantics of the in-memory one: one account per
 * (tenant, SIRET), reported as {@see InvariantViolation} "ae_account.duplicate_siret" (without the SIRET, ADR 0010) and
 * backed by the unique constraint of the table; the synchronisation list is ordered as the port says (least recently
 * synced first, ties by id).
 *
 * Unit of work: {@see self::save()} persists and flushes immediately (a flush writes every dirty managed entity), so a
 * save inside a {@see DoctrineTransactionManager} transaction is durable only when that transaction commits. Reads
 * return the managed instance, so unlike the in-memory repository a change is visible to every holder of the instance
 * before it is saved.
 *
 * Concurrency (see {@see AggregateStore}): a copy that another writer changed or erased since it was read, and an
 * instance that is not managed while its identifier is stored, are refused with "ae_account.concurrent_modification",
 * before any flush.
 */
final readonly class DoctrineAeAccountRepository implements AeAccountRepository
{
    private const ENTITY = AeAccount::class;

    /** @var AggregateStore<AeAccount> */
    private AggregateStore $store;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->store = new AggregateStore(
            $entityManager,
            self::ENTITY,
            AccountIdType::NAME,
            self::CONCURRENT_MODIFICATION,
            'The account was changed by another writer since it was read.',
        );
    }

    /**
     * @throws InvariantViolation "ae_account.concurrent_modification" or "ae_account.duplicate_siret"
     */
    #[Override]
    public function save(AeAccount $account): void
    {
        $this->store->save($account, $account->id(), $this->assertUniqueSiret(...), static function (): void {});
    }

    #[Override]
    public function get(AccountId $id): AeAccount
    {
        return $this->store->find($id) ?? throw RecordNotFound::withIdentifier('ae_account', $id->value());
    }

    #[Override]
    public function findBySiret(TenantId $tenantId, Siret $siret): ?AeAccount
    {
        return Hydration::first(
            $this->entityManager
                ->createQuery(
                    'SELECT a FROM ' . self::ENTITY . ' a WHERE a.tenantId = :tenant AND a.siret = :siret ORDER BY a.id ASC',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setParameter('siret', $siret, SiretType::NAME)
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function findLeastRecentlySynced(TenantId $tenantId, int $limit): array
    {
        return Hydration::all(
            $this->entityManager
                ->createQuery(
                    'SELECT a FROM ' . self::ENTITY . ' a WHERE a.tenantId = :tenant'
                    . ' ORDER BY a.lastSyncedAt ASC, a.id ASC',
                )
                ->setParameter('tenant', $tenantId, TenantIdType::NAME)
                ->setMaxResults($limit)
                ->toIterable(),
            self::ENTITY,
        );
    }

    #[Override]
    public function tenants(): array
    {
        /** @var list<string> $tenants */
        $tenants = $this->entityManager
            ->createQuery('SELECT DISTINCT a.tenantId FROM ' . self::ENTITY . ' a ORDER BY a.tenantId ASC')
            ->getSingleColumnResult();

        return array_map(TenantId::fromString(...), $tenants);
    }

    #[Override]
    public function delete(AccountId $id): void
    {
        $this->store->discard($id);
    }

    /**
     * @throws InvariantViolation "ae_account.duplicate_siret"
     */
    private function assertUniqueSiret(AeAccount $account): void
    {
        $others = $this->entityManager
            ->createQuery(
                'SELECT a.id FROM ' . self::ENTITY
                . ' a WHERE a.tenantId = :tenant AND a.siret = :siret AND a.id <> :id',
            )
            ->setParameter('tenant', $account->tenantId(), TenantIdType::NAME)
            ->setParameter('siret', $account->siret(), SiretType::NAME)
            ->setParameter('id', $account->id(), AccountIdType::NAME)
            ->getScalarResult();
        if ($others !== []) {
            // The SIRET is personal data (ADR 0010): never in an exception.
            throw InvariantViolation::because(
                self::DUPLICATE_SIRET,
                'The tenant already tracks an account for this SIRET.',
            );
        }
    }
}
