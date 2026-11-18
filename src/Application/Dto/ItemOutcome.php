<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

/**
 * What a use case did with one record (batch items and results). ADR 0003, ADR 0009, ADR 0014, ADR 0015.
 */
enum ItemOutcome: string
{
    /** URSSAF accepted the turnover declaration (method 050, ADR 0009). */
    case Declared = 'declared';

    /** URSSAF accepted the contribution payment order (method 060, ADR 0009). */
    case Paid = 'paid';

    /** URSSAF refused the call with business errors (final, nothing recorded by URSSAF, ADR 0009). */
    case Rejected = 'rejected';

    /** The call may have reached URSSAF without a usable answer: never resent, to be resolved manually (ADR 0003). */
    case OutcomeUnknown = 'outcome_unknown';

    /** URSSAF refused the tenant credentials (401 / 403): proven not sent, see RequeueAfterInvalidCredentials (ADR 0009). */
    case InvalidCredentials = 'invalid_credentials';

    /** A technical failure with attempts left: the record is pending again, to be retried later (ADR 0009). */
    case RetryLater = 'retry_later';

    /** Failed technically once more after the maximum number of attempts: left in error, to be handled manually (ADR 0009). */
    case Exhausted = 'exhausted';

    /** Not pending any more (claimed by another worker or in flight): left untouched, no call made (ADR 0009). */
    case NotClaimed = 'not_claimed';

    /** The same request was already recorded: its current state is returned, no call made (ADR 0009). */
    case Replayed = 'replayed';

    /** A stale submission was marked outcome unknown: an operator must check it (ADR 0003). */
    case ManualReview = 'manual_review';

    /** Method 020 observed a change of the mandate presence or of the periodicity of the account (ADR 0014). */
    case Changed = 'changed';

    /** Method 020 answered with no change for the account. */
    case Unchanged = 'unchanged';

    /** URSSAF refused the query for this record (codes attached): left untouched (ADR 0015). */
    case Failed = 'failed';
}
