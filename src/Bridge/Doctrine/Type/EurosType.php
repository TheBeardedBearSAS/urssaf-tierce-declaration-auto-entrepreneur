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

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

/**
 * Column type of {@see Euros}: an INTEGER holding the whole euros, bound as an integer. An amount is not personal data
 * (ADR 0010), so the conversion errors may quote it. PDO may give an integer back as a numeric string, which is
 * accepted when it holds an integer; anything the domain refuses (outside the 32-bit range) is reported, not clamped.
 */
final class EurosType extends Type
{
    public const NAME = 'urssaf_tdae_euros';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof Euros) {
            throw InvalidType::new($value, self::class, [Euros::class, 'null']);
        }

        return $value->toInt();
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Euros
    {
        if ($value === null) {
            return null;
        }
        if (!\is_int($value) && !\is_string($value)) {
            throw InvalidType::new($value, self::class, ['int', 'string', 'null']);
        }
        $amount = filter_var($value, \FILTER_VALIDATE_INT);
        if ($amount === false) {
            throw InvalidFormat::new((string) $value, self::class, 'integer');
        }
        try {
            return Euros::of($amount);
        } catch (InvalidValue $exception) {
            throw InvalidFormat::new((string) $value, self::class, Euros::class, $exception);
        }
    }
}
