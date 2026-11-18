<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\ShownMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

/**
 * The mandate an account view shows: the active one, else the most recent one, else none.
 */
final class ShownMandateTest extends TestCase
{
    public function testTheActiveMandateIsShownEvenWhenAMoreRecentCancelledOneExists(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->activeMandateOf($account, 'mandate-1');
        $world->cancelledMandate('mandate-2');

        $shown = ShownMandate::of($world->mandates, AccountWorld::tenant(), AccountWorld::siret());

        static::assertNotNull($shown);
        static::assertSame('mandate-1', $shown->id()->value());
    }

    public function testTheMostRecentMandateIsShownWhenNoneIsActive(): void
    {
        $world = new AccountWorld();
        $world->cancelledMandate('mandate-1');
        $world->cancelledMandate('mandate-2');

        $shown = ShownMandate::of($world->mandates, AccountWorld::tenant(), AccountWorld::siret());

        static::assertNotNull($shown);
        static::assertSame('mandate-2', $shown->id()->value());
    }

    public function testNoMandateIsShownWhenThereIsNone(): void
    {
        static::assertNull(ShownMandate::of((new AccountWorld())->mandates, AccountWorld::tenant(), AccountWorld::siret()));
    }
}
