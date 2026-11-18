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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the answer of 040, wrapped under `OutputDeclaration` or direct (ADR 0006): the amounts and the period they
 * were computed for.
 */
final class EstimateResponseMapper
{
    /**
     * @throws UnexpectedUrssafResponse when the body is not a JSON object or a field is missing or malformed
     */
    public static function map(Payload $payload): Estimate
    {
        $payload->requireJsonObject();
        $body = ResponseEnvelope::unwrap($payload, ResponseEnvelope::ESTIMATE_OR_DECLARATION);

        return Estimate::of(
            ContributionAmountsMapper::fromWire($body->requireObject('montants')),
            PeriodEncoding::decode($body->requireObject('periode')),
        );
    }
}
