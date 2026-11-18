<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

/**
 * The settlement column of the ADR 0009 table a failure of 050 / 060 belongs to.
 */
enum SubmissionFailureKind: string
{
    /** 400: the record is rejected. */
    case Rejected = 'rejected';

    /** 401 / 403: the record is moved to invalid_credentials. */
    case InvalidCredentials = 'invalid_credentials';

    /** The call may have been processed and is never resent: outcome_unknown, returned. */
    case OutcomeUnknown = 'outcome_unknown';

    /** 050 only: may have reached URSSAF but a resend is allowed: error, then pending or exhausted. */
    case Resendable = 'resendable';

    /** 429, token unavailable, local rate limit: provably not sent: error, then pending or exhausted. */
    case NotSent = 'not_sent';

    /** "credentials.not_found": nothing was sent: error, then pending or exhausted, rethrown. */
    case LocalRefusal = 'local_refusal';

    /** Anything else: outcome_unknown, rethrown. */
    case Unexpected = 'unexpected';
}
