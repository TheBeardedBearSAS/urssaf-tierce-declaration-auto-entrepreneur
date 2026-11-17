<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * What URSSAF answered to an accepted turnover declaration (050), or the certificate alone when an operator resolved
 * an unknown outcome (ADR 0009). The declaration identifier is opaque and kept as received (ADR 0006).
 */
final readonly class DeclarationResult
{
    private function __construct(
        private Certificate $certificate,
        private ?string $declarationId,
        private ?CalendarDate $dueDate,
        private ?ContributionAmounts $amounts,
    ) {}

    public static function of(
        Certificate $certificate,
        string $declarationId,
        CalendarDate $dueDate,
        ContributionAmounts $amounts,
    ): self {
        return new self($certificate, $declarationId, $dueDate, $amounts);
    }

    /**
     * Operator resolution: only the certificate is known.
     */
    public static function resolved(Certificate $certificate): self
    {
        return new self($certificate, null, null, null);
    }

    public function certificate(): Certificate
    {
        return $this->certificate;
    }

    /**
     * `idDeclaration`, null on an operator resolution.
     */
    public function declarationId(): ?string
    {
        return $this->declarationId;
    }

    public function dueDate(): ?CalendarDate
    {
        return $this->dueDate;
    }

    public function amounts(): ?ContributionAmounts
    {
        return $this->amounts;
    }

    public function isResolution(): bool
    {
        return $this->declarationId === null;
    }

    public function equals(self $other): bool
    {
        return $this->certificate->equals($other->certificate)
            && $this->declarationId === $other->declarationId
            && $this->dueDate?->toString() === $other->dueDate?->toString()
            && ContributionAmounts::same($this->amounts, $other->amounts);
    }
}
