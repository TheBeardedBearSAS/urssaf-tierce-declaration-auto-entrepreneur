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
 * Column type of {@see CalendarDate}: a DATE holding its "Y-m-d" string value.
 */
final class CalendarDateType extends Type
{
    public const NAME = 'urssaf_tdae_calendar_date';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return StringValueConversion::toDatabase($value, CalendarDate::class, self::class, static fn(CalendarDate $value): string => $value->toString());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CalendarDate
    {
        return StringValueConversion::toPhp($value, self::class, CalendarDate::fromString(...));
    }
}
