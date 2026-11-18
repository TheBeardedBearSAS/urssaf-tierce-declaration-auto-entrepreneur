<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf;

/**
 * Why URSSAF refused a call (ADR 0005). The cases are declared in the priority order used when an answer carries several
 * errors, from `SepaMandateAlreadyExists` to `Technical`; `NotFound` (a status, not a code) and `Unknown` come last.
 */
enum RejectionKind: string
{
    case SepaMandateAlreadyExists = 'sepa_mandate_already_exists';
    case MandateHeldElsewhere = 'mandate_held_elsewhere';
    case AccountUnknown = 'account_unknown';
    case NoActiveUser = 'no_active_user';
    case MandateMissing = 'mandate_missing';
    case MandateOutOfPeriod = 'mandate_out_of_period';
    case PeriodNotOpen = 'period_not_open';
    case PeriodicityMismatch = 'periodicity_mismatch';
    case InvalidBankDetails = 'invalid_bank_details';
    case UnknownBank = 'unknown_bank';
    case InvalidParameter = 'invalid_parameter';
    case NotFound = 'not_found';
    case Technical = 'technical';
    case Unknown = 'unknown';
}
