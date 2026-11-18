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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the answer of 060, wrapped under `OutputTelepaiement` or direct (ADR 0006). `idTelepaiement` is optional and
 * opaque, kept as received.
 */
final class PaymentResponseMapper
{
    /**
     * @throws UnexpectedUrssafResponse when the body is not a JSON object or a field is missing or malformed
     */
    public static function map(Payload $payload): PaymentResult
    {
        $payload->requireJsonObject();
        $body = ResponseEnvelope::unwrap($payload, ResponseEnvelope::PAYMENT);

        $period = PeriodEncoding::decode($body->requireObject('periode'));
        $dueDate = DateEncoding::decodeIsoDate($body->requireString('dateExigibilite'), 'dateExigibilite');

        return PaymentResult::of(self::certificate($body), $body->optionalString('idTelepaiement'), $dueDate, $period);
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
