<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission;

/**
 * Why the outcome of a submission is unknown (ADR 0003): shared by declarations and payments.
 */
enum OutcomeUnknownReason: string
{
    /** Transport error, 5xx or unreadable answer on 050 / 060: the request may have reached URSSAF. */
    case MayHaveReachedUrssaf = 'may_have_reached_urssaf';

    /** Left in state "submitting" past the stale submission delay. */
    case StaleSubmission = 'stale_submission';
}
