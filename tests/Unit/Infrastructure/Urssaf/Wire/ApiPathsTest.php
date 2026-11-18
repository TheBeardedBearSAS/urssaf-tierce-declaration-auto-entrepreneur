<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ApiPaths;

final class ApiPathsTest extends TestCase
{
    public function testTheNinePaths(): void
    {
        static::assertSame('/comptes', ApiPaths::findAccount());
        static::assertSame('/mandats', ApiPaths::notifyMandate());
        static::assertSame('/mandats', ApiPaths::cancelMandate());
        static::assertSame('/estimer', ApiPaths::estimateContributions());
        static::assertSame('/declarer', ApiPaths::declareTurnover());
        static::assertSame('/payer', ApiPaths::payContributions());
        static::assertSame('/sepa/mandats', ApiPaths::registerSepaMandate());
        static::assertSame('/sepa/mandats/rechercher', ApiPaths::listSepaMandates());
        static::assertSame(
            '/sepa/mandats/12345678912345/T1234567891234567891234568912345678',
            ApiPaths::revokeSepaMandate(
                Siret::fromString('12345678912345'),
                Rum::fromString('T1234567891234567891234568912345678'),
            ),
        );
    }

    public function testTheRumIsEncodedAsAPathSegment(): void
    {
        static::assertSame(
            '/sepa/mandats/12345678912345/T12%2F34%205',
            ApiPaths::revokeSepaMandate(Siret::fromString('12345678912345'), Rum::fromString('T12/34 5')),
        );
    }
}
