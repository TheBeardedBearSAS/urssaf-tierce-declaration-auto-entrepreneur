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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafEndpoints;

/**
 * Paths of the nine operations (api-reference §2), relative to {@see UrssafEndpoints::API_BASE_PATH}.
 *
 * The path of 071 holds a SIRET and a RUM, which are personal data (ADR 0010 item 6): a path is never logged, no more
 * than a query string.
 *
 * One method per operation.
 */
final class ApiPaths
{
    private const MANDATES = '/mandats';
    private const SEPA_MANDATES = '/sepa/mandats';

    /**
     * 020, `GET`.
     */
    public static function findAccount(): string
    {
        return '/comptes';
    }

    /**
     * 030, `POST` (query parameters, no body).
     */
    public static function notifyMandate(): string
    {
        return self::MANDATES;
    }

    /**
     * 031, `DELETE` (query parameters, no body).
     */
    public static function cancelMandate(): string
    {
        return self::MANDATES;
    }

    /**
     * 040, `POST`.
     */
    public static function estimateContributions(): string
    {
        return '/estimer';
    }

    /**
     * 050, `POST`.
     */
    public static function declareTurnover(): string
    {
        return '/declarer';
    }

    /**
     * 060, `POST`.
     */
    public static function payContributions(): string
    {
        return '/payer';
    }

    /**
     * 070, `POST`.
     */
    public static function registerSepaMandate(): string
    {
        return self::SEPA_MANDATES;
    }

    /**
     * 071, `DELETE`: `/sepa/mandats/{siret}/{rum}`, each part encoded as a path segment.
     */
    public static function revokeSepaMandate(Siret $siret, Rum $rum): string
    {
        return self::SEPA_MANDATES . '/' . rawurlencode($siret->toString()) . '/' . rawurlencode($rum->value());
    }

    /**
     * 075, `GET`.
     */
    public static function listSepaMandates(): string
    {
        return self::SEPA_MANDATES . '/rechercher';
    }
}
