<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\ProxyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverviewHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrinePaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;

/**
 * The "doctrine" persistence driver: services, mapping, types, named entity manager and an end-to-end declaration
 * (ADR 0015, ADR 0016).
 */
final class DoctrineWiringTest extends TestCase
{
    public function testTheDoctrineDriverWiresTheRepositoriesTheTransactionManagerAndTheStatistics(): void
    {
        $harness = self::doctrine('default');

        static::assertInstanceOf(DoctrineAeAccountRepository::class, $harness->privateService(AeAccountRepository::class, AeAccountRepository::class));
        static::assertInstanceOf(DoctrineMandateRepository::class, $harness->privateService(MandateRepository::class, MandateRepository::class));
        static::assertInstanceOf(DoctrineDeclarationRecordRepository::class, $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class));
        static::assertInstanceOf(DoctrinePaymentRecordRepository::class, $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class));
        static::assertInstanceOf(DoctrineTransactionManager::class, $harness->privateService(TransactionManager::class, TransactionManager::class));
        static::assertInstanceOf(DoctrineWorkflowStatistics::class, $harness->privateService(WorkflowStatistics::class, WorkflowStatistics::class));
    }

    public function testTheOutboxIsTheDoctrineOneUnlessAServiceIsGiven(): void
    {
        $doctrine = Harness::boot(['persistence' => ['driver' => 'doctrine'], 'outbox' => ['enabled' => true, 'sink' => 'test.sink']], false, false, true);
        $custom = Harness::boot(['persistence' => ['driver' => 'doctrine'], 'outbox' => ['enabled' => true, 'sink' => 'test.sink', 'service' => 'test.outbox']], false, false, true);

        static::assertInstanceOf(DoctrineOutbox::class, $doctrine->privateService(Outbox::class, Outbox::class));
        static::assertInstanceOf(InMemoryOutbox::class, $custom->privateService(Outbox::class, Outbox::class));
    }

    public function testTheMappingAndTheTypesAreRegisteredInTheDefaultEntityManager(): void
    {
        $harness = self::doctrine('default');
        $entityManager = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);

        foreach ([AeAccount::class, Mandate::class, DeclarationRecord::class, PaymentRecord::class] as $class) {
            static::assertFalse($entityManager->getMetadataFactory()->isTransient($class), $class);
        }
        // The bundle leaves the proxy generation mode to the host (doctrine-bundle default outside debug: never); with
        // native lazy objects, no proxy is generated at all and the mode is not configured.
        static::assertSame(
            DoctrineIntegration::nativeLazyObjectsAvailable() ? ProxyFactory::AUTOGENERATE_ALWAYS : ProxyFactory::AUTOGENERATE_NEVER,
            $entityManager->getConfiguration()->getAutoGenerateProxyClasses(),
        );
        if (DoctrineIntegration::nativeLazyObjectsAvailable()) {
            static::assertTrue($entityManager->getConfiguration()->isNativeLazyObjectsEnabled());
        }
        foreach (array_keys(DoctrineIntegration::types()) as $type) {
            static::assertTrue(Type::hasType($type), $type);
        }
    }

    public function testAnotherEntityManagerCanBeSelected(): void
    {
        $harness = Harness::boot(
            ['persistence' => ['driver' => 'doctrine', 'entity_manager' => 'urssaf']],
            false,
            false,
            [
                'dbal' => ['connections' => ['default' => ['driver' => 'pdo_sqlite', 'memory' => true], 'urssaf' => ['driver' => 'pdo_sqlite', 'memory' => true]]],
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                    'entity_managers' => ['default' => ['connection' => 'default'], 'urssaf' => ['connection' => 'urssaf']],
                ],
            ],
        );

        $mapped = $harness->privateService('doctrine.orm.urssaf_entity_manager', EntityManagerInterface::class);
        $other = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);
        static::assertFalse($mapped->getMetadataFactory()->isTransient(AeAccount::class));
        static::assertTrue($other->getMetadataFactory()->isTransient(AeAccount::class));
        static::assertInstanceOf(DoctrineAeAccountRepository::class, $harness->privateService(AeAccountRepository::class, AeAccountRepository::class));
    }

    public function testNothingIsMappedWhenAnotherDriverIsSelected(): void
    {
        $harness = Harness::boot(['persistence' => ['driver' => 'in_memory']], false, false, true);
        $entityManager = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);

        static::assertTrue($entityManager->getMetadataFactory()->isTransient(AeAccount::class));
        static::assertInstanceOf(InMemoryAeAccountRepository::class, $harness->privateService(AeAccountRepository::class, AeAccountRepository::class));
        static::assertInstanceOf(InMemoryMandateRepository::class, $harness->privateService(MandateRepository::class, MandateRepository::class));
        static::assertInstanceOf(ImmediateTransactionManager::class, $harness->privateService(TransactionManager::class, TransactionManager::class));
    }

    public function testTheProxyCacheWarmerGeneratesTheProxiesOfTheMappedEntitiesWhichThenLoadLazily(): void
    {
        $native = DoctrineIntegration::nativeLazyObjectsAvailable();
        $harness = Harness::boot(
            ['persistence' => ['driver' => 'doctrine']],
            false,
            false,
            ['dbal' => ['driver' => 'pdo_sqlite', 'memory' => true], 'orm' => ['enable_lazy_ghost_objects' => true, 'auto_generate_proxy_classes' => false]],
        );
        $entityManager = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);
        if (!$native) {
            $this->assertProxiesAreWarmedUp($harness, $entityManager);
        }
        $harness->createDatabaseSchema();
        $account = Scenario::trackedAccount($harness);
        $entityManager->clear();

        $reference = $entityManager->getReference(AeAccount::class, AccountId::fromString($account->accountId));

        static::assertInstanceOf(AeAccount::class, $reference);
        static::assertTrue($entityManager->getUnitOfWork()->isUninitializedObject($reference));
        static::assertSame(Scenario::SIRET, $reference->siret()->toString());
        static::assertFalse($entityManager->getUnitOfWork()->isUninitializedObject($reference));
    }

    public function testADeclarationIsPersistedEndToEnd(): void
    {
        $harness = self::doctrine('default');
        $harness->createDatabaseSchema();
        $entityManager = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);

        $submission = Scenario::declaration($harness);
        $entityManager->clear();

        static::assertSame('declared', $submission->declaration->status->state);
        $record = $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class)
            ->get(DeclarationRecordId::fromString($submission->declaration->recordId));
        static::assertSame(DeclarationState::Declared, $record->state());
        $overview = $harness->get(GetWorkflowOverviewHandler::class)(GetWorkflowOverview::forTenant(TenantId::fromString(Scenario::TENANT)))[0];
        static::assertSame(1, $overview->declarations['declared']);
        static::assertSame(1, $overview->mandates['notified']);
    }

    private function assertProxiesAreWarmedUp(Harness $harness, EntityManagerInterface $entityManager): void
    {
        $proxyDirectory = $entityManager->getConfiguration()->getProxyDir();
        static::assertIsString($proxyDirectory);
        (new Filesystem())->remove($proxyDirectory);

        $harness->privateService('cache_warmer', CacheWarmerAggregate::class)->warmUp($harness->kernel->getCacheDir());

        $files = glob($proxyDirectory . '/*.php');
        static::assertIsArray($files);
        $proxies = array_map(basename(...), $files);
        sort($proxies);
        static::assertSame(
            [
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurBridgeDoctrineEntityActiveMandate.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurBridgeDoctrineEntityCurrentDeclaration.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurBridgeDoctrineEntityCurrentPayment.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurBridgeDoctrineEntityOutboxMessage.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurDomainAccountAeAccount.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurDomainDeclarationDeclarationRecord.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurDomainMandateMandate.php',
                '__CG__TheBeardedBearUrssafTierceDeclarationAutoEntrepreneurDomainPaymentPaymentRecord.php',
            ],
            $proxies,
        );
    }

    private static function doctrine(string $entityManager): Harness
    {
        return Harness::boot(['persistence' => ['driver' => 'doctrine', 'entity_manager' => $entityManager]], false, false, true);
    }
}
