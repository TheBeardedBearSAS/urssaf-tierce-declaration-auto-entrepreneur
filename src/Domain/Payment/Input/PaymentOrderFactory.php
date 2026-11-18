<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Input;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Input\DeclarationPeriodFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\RawFields;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * Turns the raw body of a payment order (method 060) into a {@see PaymentOrder}, reporting every violation at once
 * (notification pattern, ADR 0000 item 4).
 *
 * Input keys: "period" (map, {@see DeclarationPeriodFactory}), "amount" (integer of whole euros, required), "iban"
 * (optional text), "rum" (optional text).
 */
final readonly class PaymentOrderFactory
{
    /**
     * @param array<array-key, mixed> $input
     */
    public function validate(#[SensitiveParameter] array $input): ValidationResult
    {
        return RawFields::validate($input, fn(RawFields $fields): ?PaymentOrder => $this->parse($fields));
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @throws ValidationFailed with every violation
     */
    public function fromInput(#[SensitiveParameter] array $input): PaymentOrder
    {
        return RawFields::resolve($input, fn(RawFields $fields): ?PaymentOrder => $this->parse($fields));
    }

    private function parse(RawFields $fields): ?PaymentOrder
    {
        $period = $fields->nested('period', (new DeclarationPeriodFactory())->parse(...));
        $rawAmount = $fields->requiredInteger('amount');
        $amount = $rawAmount === null ? null : $fields->build('amount', static fn(): Euros => Euros::of($rawAmount));
        $errorsBeforeOptionals = $fields->errorCount();
        $iban = $fields->optional('iban', Iban::fromString(...));
        $rum = $fields->optional('rum', Rum::fromString(...));
        if ($fields->errorCount() > $errorsBeforeOptionals) {
            return null;
        }

        return $period instanceof DeclarationPeriod && $amount instanceof Euros
            ? $fields->build('amount', static fn(): PaymentOrder => PaymentOrder::of($period, $amount, $iban, $rum))
            : null;
    }
}
