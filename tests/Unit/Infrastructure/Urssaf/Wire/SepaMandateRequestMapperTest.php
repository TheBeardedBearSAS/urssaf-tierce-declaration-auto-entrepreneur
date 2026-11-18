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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandateAddress;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

final class SepaMandateRequestMapperTest extends TestCase
{
    public function testTheBodyMatchesTheOfficialRequestWithoutTheNir(): void
    {
        $body = SepaMandateRequestMapper::body(WireSamples::siret(), WireSamples::newSepaMandate());

        WireBodyAssertions::assertMatchesFixture('sepa_mandate_add_request', $body, ['nir']);
        static::assertArrayNotHasKey('nir', $body);
    }

    public function testWithoutASecondLineTheKeyIsOmitted(): void
    {
        $mandate = NewSepaMandate::of(
            Bic::fromString('ABCDEFGHIJK'),
            Iban::forSepaMandate('FR0012345123451234567891A12'),
            AccountHolder::fromString('Maxime LEFORESTIER'),
            SepaMandateAddress::of('13 boulevard vauban', null, '59000', 'LILLE', 'FRANCE'),
        );

        $body = SepaMandateRequestMapper::body(WireSamples::siret(), $mandate);

        static::assertSame(
            ['bic', 'iban', 'titulaireCompte', 'adresse1', 'codePostal', 'pays', 'ville', 'siret'],
            array_keys($body),
        );
        static::assertStringNotContainsString('adresse2', JsonEncoder::encode($body));
    }
}
