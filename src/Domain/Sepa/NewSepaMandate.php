<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * Body of a new SEPA mandate (method 070) without the SIRET, which is a separate argument of the gateway (ADR 0008).
 * Personal data (ADR 0010): masked when dumped.
 */
final readonly class NewSepaMandate
{
    private function __construct(
        #[SensitiveParameter]
        private Bic $bic,
        #[SensitiveParameter]
        private Iban $iban,
        #[SensitiveParameter]
        private AccountHolder $holder,
        #[SensitiveParameter]
        private SepaMandateAddress $address,
    ) {}

    /**
     * @throws InvalidValue "iban.too_long" when the IBAN exceeds the 34 characters of method 070
     */
    public static function of(
        #[SensitiveParameter]
        Bic $bic,
        #[SensitiveParameter]
        Iban $iban,
        #[SensitiveParameter]
        AccountHolder $holder,
        #[SensitiveParameter]
        SepaMandateAddress $address,
    ): self {
        if ($iban->length() > Iban::SEPA_MANDATE_MAX_LENGTH) {
            throw InvalidValue::tooLong('iban', Iban::SEPA_MANDATE_MAX_LENGTH);
        }

        return new self($bic, $iban, $holder, $address);
    }

    public function bic(): Bic
    {
        return $this->bic;
    }

    public function iban(): Iban
    {
        return $this->iban;
    }

    public function holder(): AccountHolder
    {
        return $this->holder;
    }

    public function address(): SepaMandateAddress
    {
        return $this->address;
    }

    /**
     * @return array{bic: string, iban: string, holder: string, address: string}
     */
    public function __debugInfo(): array
    {
        return ['bic' => '***', 'iban' => '***', 'holder' => '***', 'address' => '***'];
    }
}
