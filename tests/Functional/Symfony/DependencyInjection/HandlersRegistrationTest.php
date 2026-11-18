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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverviewHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountRefresher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ApplicationRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * Every use case handler of the package is a public service of the bundle (the entry points of the host).
 */
final class HandlersRegistrationTest extends TestCase
{
    public function testEveryHandlerOfTheApplicationLayerIsAPublicService(): void
    {
        $handlers = $this->handlers();
        static::assertGreaterThanOrEqual(27, \count($handlers));
        $harness = Harness::boot(['outbox' => ['enabled' => true]]);

        foreach ($handlers as $handler) {
            static::assertTrue($harness->has($handler), $handler);
            static::assertInstanceOf($handler, $harness->get($handler));
        }
    }

    public function testTheHandlersOfTheRegistrarAreEveryHandlerButTheConditionalOnes(): void
    {
        $conditional = [GetWorkflowOverviewHandler::class, RelayOutboxHandler::class, PurgeOutboxHandler::class];
        $handlers = array_values(array_diff($this->handlers(), $conditional));

        static::assertEqualsCanonicalizing(ApplicationRegistrar::HANDLERS, $handlers);
    }

    public function testTheOutboxHandlersExistOnlyWithTheOutbox(): void
    {
        static::assertFalse(Harness::boot()->has(RelayOutboxHandler::class));
        static::assertFalse(Harness::boot()->has(PurgeOutboxHandler::class));
        static::assertTrue(Harness::boot(['outbox' => ['enabled' => true]])->has(RelayOutboxHandler::class));
        static::assertTrue(Harness::boot(['outbox' => ['enabled' => true]])->has(PurgeOutboxHandler::class));
    }

    public function testTheOverviewIsAvailableWithTheInMemoryDriver(): void
    {
        static::assertTrue(Harness::boot()->has(GetWorkflowOverviewHandler::class));
    }

    public function testTheOverviewNeedsTheStatisticsOfACustomDriver(): void
    {
        $custom = ['driver' => 'custom', 'account_repository' => 'test.accounts', 'mandate_repository' => 'test.mandates', 'declaration_record_repository' => 'test.declarations', 'payment_record_repository' => 'test.payments'];

        static::assertFalse(Harness::boot(['persistence' => $custom])->has(GetWorkflowOverviewHandler::class));
        static::assertTrue(Harness::boot(['persistence' => [...$custom, 'workflow_statistics' => 'test.statistics']])->has(GetWorkflowOverviewHandler::class));
    }

    public function testTheSharedServicesArePrivateAndAutowired(): void
    {
        $container = (new TestKernel())->definitions();

        foreach ([UnitOfWork::class, ...ApplicationRegistrar::SERVICES] as $service) {
            static::assertFalse($container->getDefinition($service)->isPublic(), $service);
        }
        static::assertEqualsCanonicalizing(
            [AccountLocator::class, AccountRefresher::class, MandateRecorder::class, MandateGuard::class, DeclarationLocator::class, DeclarationSubmitter::class, PaymentLocator::class, PaymentSubmitter::class],
            ApplicationRegistrar::SERVICES,
        );
    }

    /**
     * @return list<class-string>
     */
    private function handlers(): array
    {
        $handlers = [];
        foreach (['Command', 'Query'] as $namespace) {
            $files = glob(\dirname(__DIR__, 4) . '/src/Application/' . $namespace . '/*Handler.php');
            static::assertIsArray($files);
            foreach ($files as $file) {
                /** @var class-string $class */
                $class = 'TheBeardedBear\\UrssafTierceDeclarationAutoEntrepreneur\\Application\\' . $namespace . '\\' . basename($file, '.php');
                $handlers[] = $class;
            }
        }

        return $handlers;
    }
}
