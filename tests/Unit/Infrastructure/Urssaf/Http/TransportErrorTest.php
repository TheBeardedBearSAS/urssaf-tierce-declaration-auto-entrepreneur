<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\TransportError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;

final class TransportErrorTest extends TestCase
{
    public function testItKeepsTheClassOfTheClientExceptionOnly(): void
    {
        $original = new TransportFailure('Connection refused for https://h/sepa/mandats?siret=12345678901234&nom=FICTIF', 7);

        $error = TransportError::fromClientException($original);

        static::assertSame('The PSR-18 client failed (' . TransportFailure::class . ').', $error->getMessage());
        static::assertStringNotContainsString('sepa', $error->getMessage());
        static::assertStringNotContainsString('FICTIF', $error->getMessage());
        static::assertSame(0, $error->getCode());
        static::assertNull($error->getPrevious());
    }
}
