<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;

/**
 * Business error codes published by the AE API, verbatim (api-reference §3.4, ADR 0005). The codes are free strings in
 * the contract: an unknown code stays a raw string next to a null enum, so the catalogue never has to be complete.
 *
 * `ABN_INCONNU` and the SEPA codes are catalogued for the operations that document them; the Swagger documents
 * `CPTE_INCONNU` for every AE operation, so it is listed for 020 to 060.
 */
enum ApiErrorCode: string
{
    case InvalidParameter = 'PARAM_INVALIDE';
    case MultipleIdentifiers = 'ERR_PARAM_MULTIPLES';
    case AccountUnknown = 'CPTE_INCONNU';
    case NoActiveUser = 'ABN_INCONNU';
    case SiretAlreadyMandated = 'SIRET_DEJA_MANDATE';
    case MandateUnknown = 'MDT_INCONNU';
    case MandateOutOfPeriod = 'MDT_HORS_PERIODE';
    case PeriodNotOpen = 'APPEL_INCONNU';
    case PeriodicityInvalid = 'PERIODICITE_INVALIDE';
    case SepaMandateAlreadyExists = 'ERREUR_MANDAT_ALREADYEXIST';
    case IbanInvalid = 'ERREUR_IBAN_INVALIDE';
    case RibInvalid = 'ERREUR_RIB_INVALIDE';
    case BankUnknown = 'BANQUE_INCONNUE';
    case SepaTechnicalError = 'ERR_TECH';
    case TechnicalError = 'ERR_TECHNIQUE';

    /**
     * Normalises a raw wire code: surrounding or inner whitespace is dropped (the official documentation contains
     * codes broken across lines). The case is kept.
     */
    public static function normalise(string $rawCode): string
    {
        return preg_replace('/\s+/', '', $rawCode) ?? $rawCode;
    }

    public static function fromCode(string $rawCode): ?self
    {
        return self::tryFrom(self::normalise($rawCode));
    }

    /**
     * The operations whose documentation lists this code.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return match ($this) {
            self::InvalidParameter, self::AccountUnknown => [
                Operation::FindAccount,
                Operation::NotifyMandate,
                Operation::CancelMandate,
                Operation::Estimate,
                Operation::Declare,
                Operation::Pay,
            ],
            self::MultipleIdentifiers, self::MandateOutOfPeriod => [Operation::Estimate, Operation::Declare],
            self::NoActiveUser => [Operation::FindAccount],
            self::SiretAlreadyMandated => [Operation::NotifyMandate],
            self::MandateUnknown => [Operation::Estimate, Operation::Declare, Operation::Pay],
            self::PeriodNotOpen, self::PeriodicityInvalid => [Operation::Declare],
            self::SepaMandateAlreadyExists, self::IbanInvalid, self::RibInvalid, self::BankUnknown => [
                Operation::RegisterSepaMandate,
            ],
            self::SepaTechnicalError => [
                Operation::RegisterSepaMandate,
                Operation::RevokeSepaMandate,
                Operation::ListSepaMandates,
            ],
            self::TechnicalError => Operation::cases(),
        };
    }
}
