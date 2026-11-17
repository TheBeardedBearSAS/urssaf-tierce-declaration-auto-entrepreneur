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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;

/**
 * Content of a payment order (method 060): period, whole-euro amount, optional IBAN and RUM.
 *
 * URSSAF publishes no minimum, maximum nor combination rule (ADR 0011 item 5): only a negative amount is refused.
 * The IBAN never leaves this value object into a record (ADR 0010 item 2); it is masked when dumped.
 */
final readonly class PaymentOrder
{
    private function __construct(
        private DeclarationPeriod $period,
        private Euros $amount,
        #[SensitiveParameter]
        private ?Iban $iban,
        private ?Rum $rum,
    ) {}

    /**
     * @throws InvalidValue "payment.amount_negative"
     */
    public static function of(DeclarationPeriod $period, Euros $amount, #[SensitiveParameter] ?Iban $iban, ?Rum $rum): self
    {
        if ($amount->isNegative()) {
            throw InvalidValue::because('payment.amount_negative', 'Invalid payment: the amount must not be negative.');
        }

        return new self($period, $amount, $iban, $rum);
    }

    public function period(): DeclarationPeriod
    {
        return $this->period;
    }

    public function amount(): Euros
    {
        return $this->amount;
    }

    public function iban(): ?Iban
    {
        return $this->iban;
    }

    public function rum(): ?Rum
    {
        return $this->rum;
    }

    public function hasIban(): bool
    {
        return $this->iban instanceof Iban;
    }

    /**
     * @return array{period: string, amount: int, iban: string|null, rum: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'period' => $this->period->toString(),
            'amount' => $this->amount->toInt(),
            'iban' => $this->iban?->masked(),
            'rum' => $this->rum?->value(),
        ];
    }
}
