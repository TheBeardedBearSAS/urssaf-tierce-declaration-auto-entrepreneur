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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Creditor;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the SEPA mandates of 070 (one object) and 075 (a list), ADR 0006 and ADR 0008. Every field is optional.
 *
 * The echoed `siret` and `rum` of an accepted registration (070) are read leniently: an invalid one is `null`, so that
 * a registration URSSAF accepted never becomes an unknown outcome. On 075 they are read strictly.
 */
final class SepaMandateResponseMapper
{
    /**
     * @throws UnexpectedUrssafResponse when the body is not a JSON object or a field has the wrong type
     */
    public static function mapRegistered(Payload $payload): SepaMandate
    {
        $payload->requireJsonObject();

        return self::fromEntry($payload, false);
    }

    /**
     * @return list<SepaMandate>
     *
     * @throws UnexpectedUrssafResponse when the body is not a JSON array of objects or a field is malformed
     */
    public static function mapList(Payload $payload): array
    {
        $payload->requireJsonList();

        return array_map(static fn(Payload $entry): SepaMandate => self::fromEntry($entry, true), $payload->entries());
    }

    private static function fromEntry(Payload $entry, bool $strict): SepaMandate
    {
        return new SepaMandate(
            self::rum($entry, $strict),
            $entry->optionalString('nomBanque'),
            $entry->optionalString('iban'),
            $entry->optionalString('titulaireCompte'),
            self::siret($entry, $strict),
            self::creditor($entry->object('creancier')),
        );
    }

    /**
     * @mago-expect lint:no-boolean-flag-parameter One policy flag shared by the strict (075) and lenient (070) readers.
     */
    private static function rum(Payload $entry, bool $strict): ?Rum
    {
        $value = $entry->optionalString('rum');

        if ($value === null) {
            return null;
        }

        try {
            return Rum::fromString($value);
        } catch (InvalidValue $exception) {
            return $strict ? throw UnexpectedUrssafResponse::causedBy('field "rum" is not a valid RUM', $exception) : null;
        }
    }

    /**
     * @mago-expect lint:no-boolean-flag-parameter One policy flag shared by the strict (075) and lenient (070) readers.
     */
    private static function siret(Payload $entry, bool $strict): ?Siret
    {
        $value = $entry->optionalString('siret');

        if ($value === null) {
            return null;
        }

        try {
            return Siret::fromString($value);
        } catch (InvalidValue $exception) {
            return $strict ? throw UnexpectedUrssafResponse::causedBy('field "siret" is not a SIRET', $exception) : null;
        }
    }

    private static function creditor(?Payload $creditor): ?Creditor
    {
        if (!$creditor instanceof Payload) {
            return null;
        }

        return new Creditor(
            $creditor->optionalString('nom'),
            $creditor->optionalString('ics'),
            $creditor->optionalString('adresse1'),
            $creditor->optionalString('adresse2'),
            $creditor->optionalString('codePostal'),
            $creditor->optionalString('pays'),
            self::city($creditor->optionalString('ville')),
        );
    }

    /**
     * `ville` of the creditor carries a trailing space in the PDF example (ADR 0006): trimmed, and an empty result
     * is `null`.
     */
    private static function city(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $trimmed = trim($city);

        return $trimmed === '' ? null : $trimmed;
    }
}
