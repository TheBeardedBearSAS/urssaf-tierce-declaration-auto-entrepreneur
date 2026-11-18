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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Serializer\PaymentResultSerializer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\Conversion\JsonConversion;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;

/**
 * JSON column type of an optional {@see PaymentResult}: a null result is stored as SQL NULL. The document holds the amounts and the
 * identifiers URSSAF answered (personal data, ADR 0010), and a stored document the domain refuses is never quoted in
 * the conversion error.
 */
final class PaymentResultType extends Type
{
    public const NAME = 'urssaf_tdae_payment_result';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return JsonConversion::toDatabase($value, PaymentResult::class, self::class, PaymentResultSerializer::toArray(...));
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?PaymentResult
    {
        return JsonConversion::decode($value, self::class, PaymentResult::class, PaymentResultSerializer::fromArray(...));
    }
}
