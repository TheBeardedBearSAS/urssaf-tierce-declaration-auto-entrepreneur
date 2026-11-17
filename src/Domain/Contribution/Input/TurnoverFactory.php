<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\RawFields;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * Turns the raw turnover of a host input into a {@see Turnover}, reporting every violation at once (notification
 * pattern, ADR 0000 item 4).
 *
 * Input keys, all optional integers of whole euros: "sales", "furnished_rental", "commercial_services",
 * "other_services", "cipav_receipts". A float is refused ("<key>.invalid_type").
 */
final readonly class TurnoverFactory
{
    /**
     * @param array<array-key, mixed> $input
     */
    public function validate(#[SensitiveParameter] array $input): ValidationResult
    {
        return RawFields::validate($input, $this->parse(...));
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @throws ValidationFailed with every violation
     */
    public function fromInput(#[SensitiveParameter] array $input): Turnover
    {
        return RawFields::resolve($input, $this->parse(...));
    }

    /**
     * Reads the turnover out of the fields of a map; also used to read the nested "turnover" of the declaration.
     */
    public function parse(RawFields $fields): ?Turnover
    {
        $errorsBefore = $fields->errorCount();
        $turnover = $fields->build('', static fn(): Turnover => Turnover::of(
            self::euros($fields, 'sales'),
            self::euros($fields, 'furnished_rental'),
            self::euros($fields, 'commercial_services'),
            self::euros($fields, 'other_services'),
            self::euros($fields, 'cipav_receipts'),
        ));

        return $fields->errorCount() > $errorsBefore ? null : $turnover;
    }

    private static function euros(RawFields $fields, string $key): ?Euros
    {
        $raw = $fields->integer($key);

        return $raw === null ? null : $fields->build($key, static fn(): Euros => Euros::of($raw));
    }
}
