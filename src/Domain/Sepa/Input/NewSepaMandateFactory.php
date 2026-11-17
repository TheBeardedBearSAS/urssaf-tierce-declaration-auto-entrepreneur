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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandateAddress;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\RawFields;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * Turns the raw body of a new SEPA mandate (method 070) into a {@see NewSepaMandate}, reporting every violation at
 * once (notification pattern, ADR 0000 item 4).
 *
 * Input keys: "bic", "iban", "account_holder", "address" (map: "first_line", "second_line" optional, "postal_code",
 * "city", "country"). The SIRET is a separate argument of the gateway (ADR 0008).
 */
final readonly class NewSepaMandateFactory
{
    private const PLACEHOLDER = '-';

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
    public function fromInput(#[SensitiveParameter] array $input): NewSepaMandate
    {
        return RawFields::resolve($input, $this->parse(...));
    }

    private function parse(RawFields $fields): ?NewSepaMandate
    {
        $bic = $fields->required('bic', Bic::fromString(...));
        $iban = $fields->required('iban', Iban::forSepaMandate(...));
        $holder = $fields->required('account_holder', AccountHolder::fromString(...));
        $address = $fields->nested('address', $this->parseAddress(...));

        return $bic instanceof Bic && $iban instanceof Iban && $holder instanceof AccountHolder && $address instanceof SepaMandateAddress
            ? NewSepaMandate::of($bic, $iban, $holder, $address)
            : null;
    }

    private function parseAddress(RawFields $fields): ?SepaMandateAddress
    {
        $errorsBefore = $fields->errorCount();
        $firstLine = $fields->requiredText('first_line');
        $secondLine = $fields->text('second_line');
        $postalCode = $fields->requiredText('postal_code');
        $city = $fields->requiredText('city');
        $country = $fields->requiredText('country');
        $address = $fields->build('', static fn(): SepaMandateAddress => SepaMandateAddress::of(
            $firstLine ?? self::PLACEHOLDER,
            $secondLine,
            $postalCode ?? self::PLACEHOLDER,
            $city ?? self::PLACEHOLDER,
            $country ?? self::PLACEHOLDER,
        ));

        return $fields->errorCount() > $errorsBefore ? null : $address;
    }
}
