<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * What URSSAF answered to an accepted payment order (060), or the certificate alone when an operator resolved an unknown
 * outcome (ADR 0009). The payment identifier (`idTelepaiement`) is optional and opaque, kept as received (ADR 0006).
 */
final readonly class PaymentResult
{
    private function __construct(
        private Certificate $certificate,
        private ?string $paymentId,
        private ?CalendarDate $dueDate,
        private ?DeclarationPeriod $period,
    ) {}

    /**
     * 060 accepted.
     */
    public static function of(Certificate $certificate, ?string $paymentId, CalendarDate $dueDate, DeclarationPeriod $period): self
    {
        return new self($certificate, $paymentId, $dueDate, $period);
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
     * `idTelepaiement`, null when URSSAF sent none and on an operator resolution.
     */
    public function paymentId(): ?string
    {
        return $this->paymentId;
    }

    public function dueDate(): ?CalendarDate
    {
        return $this->dueDate;
    }

    public function period(): ?DeclarationPeriod
    {
        return $this->period;
    }

    /**
     * True when the result comes from an operator resolution (only the certificate is known).
     */
    public function isResolution(): bool
    {
        return !$this->dueDate instanceof CalendarDate;
    }

    public function equals(self $other): bool
    {
        return $this->certificate->equals($other->certificate)
            && $this->paymentId === $other->paymentId
            && $this->dueDate?->toString() === $other->dueDate?->toString()
            && $this->period?->toString() === $other->period?->toString();
    }
}
