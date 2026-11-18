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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\SimulatedGatewayFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;
use ValueError;

/**
 * The runtime guard of the "gateway.simulated" rule.
 */
final class SimulatedGatewayFactoryTest extends TestCase
{
    public function testTheSandboxGetsTheSimulator(): void
    {
        static::assertSame([], SimulatedGatewayFactory::create('sandbox', new FixedClock('2026-11-17T09:00:00Z'))->calls());
    }

    public function testTheClockOfThePackageIsGivenToTheSimulator(): void
    {
        $gateway = SimulatedGatewayFactory::create('sandbox', new FixedClock('2027-02-10T09:00:00Z'));
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate(), Periodicity::Monthly);
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $period = $gateway->estimateContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::turnover())->period();

        static::assertSame('2027-1', $period->year() . '-' . DeclarationPeriods::numberOf($period));
    }

    public function testProductionIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"gateway.simulated" is meant for tests and cannot be used with the production environment.');

        SimulatedGatewayFactory::create('production', new FixedClock('2026-11-17T09:00:00Z'));
    }

    public function testAnUnknownEnvironmentIsRefused(): void
    {
        $this->expectException(ValueError::class);

        SimulatedGatewayFactory::create('staging', new FixedClock('2026-11-17T09:00:00Z'));
    }
}
