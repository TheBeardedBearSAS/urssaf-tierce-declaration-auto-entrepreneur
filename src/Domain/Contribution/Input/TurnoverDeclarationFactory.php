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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Input\DeclarationPeriodFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\RawFields;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;

/**
 * Turns the raw body of a turnover declaration (method 050) into a {@see TurnoverDeclaration}, reporting every
 * violation at once with nested paths ("period.month", "turnover.furnished_rental", notification pattern, ADR 0000
 * item 4).
 *
 * Input keys: "period" (map, {@see DeclarationPeriodFactory}), "turnover" (required map, {@see TurnoverFactory}; an
 * empty map is valid).
 */
final readonly class TurnoverDeclarationFactory
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
    public function fromInput(#[SensitiveParameter] array $input): TurnoverDeclaration
    {
        return RawFields::resolve($input, $this->parse(...));
    }

    private function parse(RawFields $fields): ?TurnoverDeclaration
    {
        $period = $fields->nested('period', (new DeclarationPeriodFactory())->parse(...));
        $errorsBefore = $fields->errorCount();
        $turnover = $fields->optionalNested('turnover', (new TurnoverFactory())->parse(...));
        if ($turnover === null && $fields->errorCount() === $errorsBefore) {
            $fields->add(Violation::error('turnover', 'turnover.required', 'The turnover is required.'));
        }

        return $period instanceof DeclarationPeriod && $turnover instanceof Turnover
            ? TurnoverDeclaration::of($period, $turnover)
            : null;
    }
}
