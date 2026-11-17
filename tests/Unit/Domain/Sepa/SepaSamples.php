<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandateAddress;

/**
 * Fictitious values of the official documentation examples (api-reference §2).
 */
final class SepaSamples
{
    public const IBAN = 'FR7630001007941234567890185';
    public const BIC = 'ABCDEFGHIJK';
    public const HOLDER = 'Maxime LEFORESTIER';
    public const RUM = 'T1234567891234567891234568912345678';

    public static function address(): SepaMandateAddress
    {
        return SepaMandateAddress::of('13 boulevard vauban', null, '59000', 'LILLE', 'FRANCE');
    }

    public static function newMandate(): NewSepaMandate
    {
        return NewSepaMandate::of(Bic::fromString(self::BIC), Iban::forSepaMandate(self::IBAN), AccountHolder::fromString(self::HOLDER), self::address());
    }
}
