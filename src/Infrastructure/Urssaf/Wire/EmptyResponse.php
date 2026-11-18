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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Bodies of the operations that answer nothing (ADR 0002): 030 and 031 answer an empty 200 (a blank body or `{}`),
 * 071 answers 204 or an empty 200 (a blank body only). Anything else is an unexpected answer, so that a success never
 * hides a body this version cannot read.
 */
final class EmptyResponse
{
    /**
     * @throws UnexpectedUrssafResponse unless the body is blank or `{}`
     */
    public static function assertEmpty(#[SensitiveParameter] string $body): void
    {
        if (self::isBlank($body) || self::isEmptyObject($body)) {
            return;
        }

        throw self::refusal();
    }

    /**
     * @throws UnexpectedUrssafResponse unless the body is blank
     */
    public static function assertNoContent(#[SensitiveParameter] string $body): void
    {
        if (self::isBlank($body)) {
            return;
        }

        throw self::refusal();
    }

    private static function isBlank(#[SensitiveParameter] string $body): bool
    {
        return trim($body) === '';
    }

    private static function isEmptyObject(#[SensitiveParameter] string $body): bool
    {
        try {
            $payload = JsonDecoder::decode($body);
            $payload->requireJsonObject();
        } catch (UnexpectedUrssafResponse) {
            return false;
        }

        return $payload->isEmpty();
    }

    private static function refusal(): UnexpectedUrssafResponse
    {
        return UnexpectedUrssafResponse::invalidPayload('expected an empty body');
    }
}
