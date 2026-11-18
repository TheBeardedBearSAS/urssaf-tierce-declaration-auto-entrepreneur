<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;

/**
 * Body of the SEPA mandate registration (070). The SIRET comes from the caller (ADR 0008). The `nir` field of the
 * contract is never written: this SDK never holds one (ADR 0008, ADR 0010).
 */
final class SepaMandateRequestMapper
{
    /**
     * @return array<string, string>
     */
    public static function body(Siret $siret, #[SensitiveParameter] NewSepaMandate $mandate): array
    {
        $address = $mandate->address();

        $body = [
            'bic' => $mandate->bic()->value(),
            'iban' => $mandate->iban()->value(),
            'titulaireCompte' => $mandate->holder()->value(),
            'adresse1' => $address->firstLine(),
        ];

        $secondLine = $address->secondLine();
        if ($secondLine !== null) {
            $body['adresse2'] = $secondLine;
        }

        $body['codePostal'] = $address->postalCode();
        $body['pays'] = $address->country();
        $body['ville'] = $address->city();
        $body['siret'] = $siret->toString();

        return $body;
    }
}
