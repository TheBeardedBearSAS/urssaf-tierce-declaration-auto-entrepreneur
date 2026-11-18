<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrinePaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;

/**
 * Repositories, transaction manager and statistics of the selected persistence driver ("in_memory", "doctrine" or
 * "custom"), ADR 0015 item 4 and ADR 0016.
 *
 * @phpstan-type Persistence array{driver: string, account_repository: ?string, mandate_repository: ?string, declaration_record_repository: ?string, payment_record_repository: ?string, transaction_manager: ?string, workflow_statistics: ?string, entity_manager: string}
 */
final readonly class PersistenceRegistrar
{
    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Persistence $persistence
     */
    public function register(array $persistence): void
    {
        if ($persistence['driver'] === BundleConfiguration::DRIVER_DOCTRINE) {
            $this->registerDoctrine(new Reference(ServiceIds::entityManager($persistence['entity_manager'])));

            return;
        }
        if ($persistence['driver'] === BundleConfiguration::DRIVER_CUSTOM) {
            $this->registerCustom($persistence);

            return;
        }
        $this->services->set(AeAccountRepository::class, InMemoryAeAccountRepository::class);
        $this->services->set(MandateRepository::class, InMemoryMandateRepository::class);
        $this->services->set(DeclarationRecordRepository::class, InMemoryDeclarationRecordRepository::class);
        $this->services->set(PaymentRecordRepository::class, InMemoryPaymentRecordRepository::class);
        $this->services->set(TransactionManager::class, ImmediateTransactionManager::class);
        $this->services->set(WorkflowStatistics::class, InMemoryWorkflowStatistics::class)->args([
            new Reference(MandateRepository::class),
            new Reference(DeclarationRecordRepository::class),
            new Reference(PaymentRecordRepository::class),
        ]);
    }

    /**
     * Whether a WorkflowStatistics is available: always, except with the "custom" driver without "workflow_statistics".
     */
    public static function hasStatistics(string $driver, ?string $statisticsService): bool
    {
        return $driver !== BundleConfiguration::DRIVER_CUSTOM || $statisticsService !== null;
    }

    /**
     * @param Persistence $persistence
     */
    private function registerCustom(array $persistence): void
    {
        $this->services->alias(AeAccountRepository::class, (string) $persistence['account_repository']);
        $this->services->alias(MandateRepository::class, (string) $persistence['mandate_repository']);
        $this->services->alias(DeclarationRecordRepository::class, (string) $persistence['declaration_record_repository']);
        $this->services->alias(PaymentRecordRepository::class, (string) $persistence['payment_record_repository']);
        $this->registerTransactionManager($persistence['transaction_manager']);
        if ($persistence['workflow_statistics'] !== null) {
            $this->services->alias(WorkflowStatistics::class, $persistence['workflow_statistics']);
        }
    }

    private function registerDoctrine(Reference $entityManager): void
    {
        $this->services->set(AeAccountRepository::class, DoctrineAeAccountRepository::class)->args([$entityManager]);
        $this->services->set(MandateRepository::class, DoctrineMandateRepository::class)->args([$entityManager]);
        $this->services->set(DeclarationRecordRepository::class, DoctrineDeclarationRecordRepository::class)->args([$entityManager]);
        $this->services->set(PaymentRecordRepository::class, DoctrinePaymentRecordRepository::class)->args([$entityManager]);
        $this->services->set(TransactionManager::class, DoctrineTransactionManager::class)->args([$entityManager]);
        $this->services->set(WorkflowStatistics::class, DoctrineWorkflowStatistics::class)->args([$entityManager]);
    }

    private function registerTransactionManager(?string $service): void
    {
        if ($service !== null) {
            $this->services->alias(TransactionManager::class, $service);

            return;
        }
        $this->services->set(TransactionManager::class, ImmediateTransactionManager::class);
    }
}
