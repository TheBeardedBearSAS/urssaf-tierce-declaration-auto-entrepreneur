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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Column type of the auto-entrepreneur birth date of the account ({@see CalendarDate}, ADR 0010: personal data): a
 * DATE holding its "Y-m-d" string value. A type of its own, so that an application encrypts the birth date alone
 * (docs/doctrine.md) without touching the other dates. A stored value the domain refuses, or a foreign PHP value, is
 * never quoted in the conversion error (ADR 0016).
 */
final class BirthDateType extends Type
{
    public const NAME = 'urssaf_tdae_birth_date';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return StringValueConversion::toDatabaseWithoutValue($value, CalendarDate::class, self::class, static fn(CalendarDate $value): string => $value->toString());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CalendarDate
    {
        return StringValueConversion::toPhpWithoutValue($value, self::class, CalendarDate::fromString(...));
    }
}
