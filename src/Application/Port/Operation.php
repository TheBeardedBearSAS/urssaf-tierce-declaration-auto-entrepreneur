<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port;

/**
 * The nine methods of the URSSAF "API Tierce Déclaration auto-entrepreneur", numbered as in the specification (ADR 0003).
 *
 * There is no case for the token request: it is not a method and is not counted by the rate limiter (ADR 0013).
 */
enum Operation: string
{
    /** 020 find the account of a SIRET */
    case FindAccount = '020';

    /** 030 notify the mandate of the platform */
    case NotifyMandate = '030';

    /** 031 cancel the mandate of the platform */
    case CancelMandate = '031';

    /** 040 estimate the contributions of a turnover */
    case Estimate = '040';

    /** 050 declare a turnover */
    case Declare = '050';

    /** 060 pay the contributions */
    case Pay = '060';

    /** 070 register a SEPA mandate */
    case RegisterSepaMandate = '070';

    /** 071 revoke a SEPA mandate */
    case RevokeSepaMandate = '071';

    /** 075 list the SEPA mandates of a SIRET */
    case ListSepaMandates = '075';

    /**
     * Retry class of the operation (ADR 0003). A 429 or a local rate-limit refusal is retryable for every class: the
     * call provably did not reach URSSAF.
     */
    public function retryClass(): OperationRetryClass
    {
        return match ($this) {
            self::FindAccount, self::Estimate, self::ListSepaMandates => OperationRetryClass::Safe,
            self::Pay => OperationRetryClass::NeverResent,
            self::NotifyMandate,
            self::CancelMandate,
            self::Declare,
            self::RegisterSepaMandate,
            self::RevokeSepaMandate => OperationRetryClass::UnspecifiedWrite,
        };
    }

    /**
     * Short English name for logs and operator messages (no personal data).
     */
    public function label(): string
    {
        return match ($this) {
            self::FindAccount => 'find account',
            self::NotifyMandate => 'notify mandate',
            self::CancelMandate => 'cancel mandate',
            self::Estimate => 'estimate contributions',
            self::Declare => 'declare turnover',
            self::Pay => 'pay contributions',
            self::RegisterSepaMandate => 'register SEPA mandate',
            self::RevokeSepaMandate => 'revoke SEPA mandate',
            self::ListSepaMandates => 'list SEPA mandates',
        };
    }

    /**
     * True for the SEPA methods (070, 071, 075): their error bodies and 404 / 409 statuses are specific (ADR 0002).
     */
    public function isSepa(): bool
    {
        return match ($this) {
            self::RegisterSepaMandate, self::RevokeSepaMandate, self::ListSepaMandates => true,
            self::FindAccount, self::NotifyMandate, self::CancelMandate, self::Estimate, self::Declare, self::Pay => false,
        };
    }
}
