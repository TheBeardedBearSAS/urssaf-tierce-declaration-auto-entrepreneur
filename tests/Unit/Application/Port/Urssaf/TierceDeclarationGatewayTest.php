<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Port\Urssaf;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionEstimator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;

/**
 * The gateway is exactly the union of the eight sub-ports, one method per URSSAF operation.
 */
final class TierceDeclarationGatewayTest extends TestCase
{
    public function testTheGatewayExtendsEverySubPort(): void
    {
        $interfaces = array_keys((new ReflectionClass(TierceDeclarationGateway::class))->getInterfaces());
        sort($interfaces);

        $expected = [
            AccountReader::class,
            ContributionEstimator::class,
            ContributionPayer::class,
            MandateNotifier::class,
            SepaMandateFinder::class,
            SepaMandateRegistrar::class,
            SepaMandateRevoker::class,
            TurnoverDeclarer::class,
        ];
        sort($expected);

        static::assertSame($expected, $interfaces);
    }

    public function testTheGatewayHasOneMethodPerOperation(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(TierceDeclarationGateway::class))->getMethods(),
        );
        sort($methods);

        static::assertSame(
            [
                'cancelMandate',
                'declareTurnover',
                'estimateContributions',
                'findAccount',
                'listSepaMandates',
                'notifyMandate',
                'payContributions',
                'registerSepaMandate',
                'revokeSepaMandate',
            ],
            $methods,
        );
    }
}
