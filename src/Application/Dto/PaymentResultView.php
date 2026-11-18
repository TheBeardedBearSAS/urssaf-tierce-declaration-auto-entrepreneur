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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;

/**
 * Read model of the result of a payment (060) or of its resolution by the operator. The period is null for a
 * resolution (the operator gives only the certificat). `dueDate` is a `yyyy-MM-dd` date.
 */
final readonly class PaymentResultView
{
    private function __construct(
        public string $certificat,
        public ?string $idTelepaiement,
        public ?string $dueDate,
        public ?string $periodicity,
        public ?int $periodYear,
        public ?int $periodNumber,
        public bool $resolved,
    ) {}

    public static function fromResult(PaymentResult $result): self
    {
        $period = $result->period();

        return new self(
            $result->certificate()->value(),
            $result->paymentId(),
            $result->dueDate()?->toString(),
            $period?->periodicity()->value,
            $period?->year(),
            $period instanceof DeclarationPeriod ? DeclarationPeriods::numberOf($period) : null,
            $result->isResolution(),
        );
    }
}
