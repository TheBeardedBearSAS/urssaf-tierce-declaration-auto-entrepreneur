<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;

/**
 * Read model of the result of an accepted 050: the certificat, the URSSAF declaration id, the due date and the amounts.
 * A result the operator resolved by hand ({@see self::$resolved}) holds the certificat only: the other fields are null.
 */
final readonly class DeclarationResultView
{
    private function __construct(
        public string $certificat,
        public ?string $idDeclaration,
        public ?string $dueDate,
        public ?ContributionAmountsView $amounts,
        public bool $resolved,
    ) {}

    public static function fromResult(DeclarationResult $result): self
    {
        $amounts = $result->amounts();

        return new self(
            $result->certificate()->value(),
            $result->declarationId(),
            $result->dueDate()?->toString(),
            $amounts instanceof ContributionAmounts ? ContributionAmountsView::fromAmounts($amounts) : null,
            $result->isResolution(),
        );
    }
}
