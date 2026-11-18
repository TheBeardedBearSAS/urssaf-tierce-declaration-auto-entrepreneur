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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * The optional wrapper of the 040, 050 and 060 answers (ADR 0006): the same object is sent either directly or under
 * `OutputDeclaration` / `OutputTelepaiement`. An absent key means a direct answer; a present key must hold a
 * non-empty object.
 */
final class ResponseEnvelope
{
    public const ESTIMATE_OR_DECLARATION = 'OutputDeclaration';
    public const PAYMENT = 'OutputTelepaiement';

    public static function shapeOf(Payload $payload, string $wrapperKey): ResponseShape
    {
        return $payload->has($wrapperKey) ? ResponseShape::Wrapped : ResponseShape::Direct;
    }

    /**
     * @throws UnexpectedUrssafResponse when the wrapper key is present but does not hold a non-empty object
     */
    public static function unwrap(Payload $payload, string $wrapperKey): Payload
    {
        if (self::shapeOf($payload, $wrapperKey) === ResponseShape::Direct) {
            return $payload;
        }

        $inner = self::objectOrNull($payload, $wrapperKey);

        if (!$inner instanceof Payload || $inner->isEmpty()) {
            throw UnexpectedUrssafResponse::invalidPayload(\sprintf('field "%s" is not a non-empty object', $wrapperKey));
        }

        return $inner;
    }

    private static function objectOrNull(Payload $payload, string $key): ?Payload
    {
        try {
            return $payload->object($key);
        } catch (UnexpectedUrssafResponse) {
            return null;
        }
    }
}
