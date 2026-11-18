<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The last request that reached the scripted URSSAF platform.
 */
final class Requests
{
    private function __construct() {}

    public static function lastBody(Harness $harness): string
    {
        return (string) self::last($harness)->getBody();
    }

    public static function lastUri(Harness $harness): string
    {
        return (string) self::last($harness)->getUri();
    }

    private static function last(Harness $harness): RequestInterface
    {
        // @mago-expect analysis:mixed-assignment The mock client is untyped: the assertion below narrows the request.
        $request = $harness->client->getLastRequest();
        Assert::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }
}
