<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\Conversion\StringValueConversion;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;

/**
 * Column type of {@see Siret} (personal data, ADR 0010): a VARCHAR holding its string value. A stored value the
 * domain refuses, or a foreign PHP value, is never quoted in the conversion error (ADR 0016).
 */
final class SiretType extends Type
{
    public const NAME = 'urssaf_tdae_siret';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return StringValueConversion::toDatabaseWithoutValue($value, Siret::class, self::class, static fn(Siret $value): string => $value->toString());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Siret
    {
        return StringValueConversion::toPhpWithoutValue($value, self::class, Siret::fromString(...));
    }
}
