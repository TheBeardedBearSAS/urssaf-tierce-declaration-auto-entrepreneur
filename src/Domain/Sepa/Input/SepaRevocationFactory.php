<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\RawFields;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * Turns the raw body of a SEPA mandate revocation (method 071) into a {@see SepaRevocation}, reporting every
 * violation at once (notification pattern, ADR 0000 item 4).
 *
 * Input keys: "siret", "rum", "effective_date" (optional, Y-m-d).
 */
final readonly class SepaRevocationFactory
{
    /**
     * @param array<array-key, mixed> $input
     */
    public function validate(#[SensitiveParameter] array $input): ValidationResult
    {
        return RawFields::validate($input, fn(RawFields $fields): ?SepaRevocation => $this->parse($fields));
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @throws ValidationFailed with every violation
     */
    public function fromInput(#[SensitiveParameter] array $input): SepaRevocation
    {
        return RawFields::resolve($input, fn(RawFields $fields): ?SepaRevocation => $this->parse($fields));
    }

    private function parse(RawFields $fields): ?SepaRevocation
    {
        $siret = $fields->required('siret', Siret::fromString(...));
        $rum = $fields->required('rum', Rum::fromString(...));
        $errorsBeforeOptionals = $fields->errorCount();
        $effectiveDate = $fields->optional('effective_date', CalendarDate::fromString(...));
        if ($fields->errorCount() > $errorsBeforeOptionals) {
            return null;
        }

        return $siret instanceof Siret && $rum instanceof Rum ? SepaRevocation::of($siret, $rum, $effectiveDate) : null;
    }
}
