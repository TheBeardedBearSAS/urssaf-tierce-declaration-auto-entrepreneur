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
 * Technical cause of an unknown outcome (ADR 0003). The reason stored on a record is
 * {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason}.
 */
enum OutcomeUnknownCause: string
{
    /** The request may have been sent but no HTTP answer came back (connection reset, timeout). */
    case Transport = 'transport';

    /** URSSAF answered a 5xx. */
    case ServerError = 'server_error';

    /** URSSAF answered 2xx but the answer could not be read: the call was processed. */
    case UnreadableResponse = 'unreadable_response';
}
