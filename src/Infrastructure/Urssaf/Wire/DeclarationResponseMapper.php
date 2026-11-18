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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the answer of 050, wrapped under `OutputDeclaration` or direct (ADR 0006). The certificate and the declaration
 * identifier are kept as received (identifiers are opaque).
 */
final class DeclarationResponseMapper
{
    /**
     * @throws UnexpectedUrssafResponse when the body is not a JSON object or a field is missing or malformed
     */
    public static function map(Payload $payload): DeclarationResult
    {
        $payload->requireJsonObject();
        $body = ResponseEnvelope::unwrap($payload, ResponseEnvelope::ESTIMATE_OR_DECLARATION);

        $amounts = ContributionAmountsMapper::fromWire($body->requireObject('montants'));
        $dueDate = DateEncoding::decodeIsoDate($body->requireString('dateExigibilite'), 'dateExigibilite');

        return DeclarationResult::of(
            self::certificate($body),
            $body->requireString('idDeclaration'),
            $dueDate,
            $amounts,
        );
    }

    private static function certificate(Payload $body): Certificate
    {
        try {
            return Certificate::fromResponse($body->requireString('certificat'));
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy('field "certificat" is blank', $exception);
        }
    }
}
