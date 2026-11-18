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

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * "gateway.simulated: true": the whole workflow runs against the in-memory URSSAF platform, as in the tests of a host.
 */
final class SimulatedGatewayTest extends TestCase
{
    public function testTheRealGatewayIsUsedByDefault(): void
    {
        $harness = Harness::boot();

        static::assertInstanceOf(UrssafHttpGateway::class, $harness->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class));
        static::assertFalse($harness->has(SimulatedAeGateway::class));
    }

    public function testTheWorkflowRunsAgainstTheSimulatorWithoutNetwork(): void
    {
        $harness = Harness::boot(['gateway' => ['simulated' => true]]);
        $urssaf = $harness->get(SimulatedAeGateway::class);
        static::assertSame($urssaf, $harness->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class));
        $urssaf->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate(), Periodicity::Monthly);
        $tenant = SimulatorSamples::tenant();

        $harness->get(NotifyMandateHandler::class)(new NotifyMandate($tenant, SimulatorSamples::siret(), SimulatorSamples::birthDate()));
        $submission = $harness->get(DeclareTurnoverHandler::class)(new DeclareTurnover($tenant, SimulatorSamples::siret(), SimulatorSamples::declaration()));

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame(['030', '050'], $urssaf->calls());
        static::assertSame([], $harness->client->getRequests());
    }

    public function testTheSimulatorIsRefusedWhenAPlaceholderResolvesToProduction(): void
    {
        $_ENV['URSSAF_TEST_ENVIRONMENT'] = 'production';
        $_SERVER['URSSAF_TEST_ENVIRONMENT'] = 'production';
        try {
            $harness = Harness::boot(['environment' => '%env(URSSAF_TEST_ENVIRONMENT)%', 'gateway' => ['simulated' => true]]);

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('"gateway.simulated" is meant for tests and cannot be used with the production environment.');

            $harness->get(SimulatedAeGateway::class);
        } finally {
            unset($_ENV['URSSAF_TEST_ENVIRONMENT'], $_SERVER['URSSAF_TEST_ENVIRONMENT']);
        }
    }

    public function testTheSimulatorIsAcceptedWhenAPlaceholderResolvesToTheSandbox(): void
    {
        $_ENV['URSSAF_TEST_ENVIRONMENT'] = 'sandbox';
        $_SERVER['URSSAF_TEST_ENVIRONMENT'] = 'sandbox';
        try {
            $harness = Harness::boot(['environment' => '%env(URSSAF_TEST_ENVIRONMENT)%', 'gateway' => ['simulated' => true]]);

            static::assertInstanceOf(SimulatedAeGateway::class, $harness->get(SimulatedAeGateway::class));
        } finally {
            unset($_ENV['URSSAF_TEST_ENVIRONMENT'], $_SERVER['URSSAF_TEST_ENVIRONMENT']);
        }
    }
}
