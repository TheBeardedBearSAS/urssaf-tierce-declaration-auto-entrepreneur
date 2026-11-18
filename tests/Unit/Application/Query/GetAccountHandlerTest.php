<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Query;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

final class GetAccountHandlerTest extends TestCase
{
    public function testTheActiveMandateIsShown(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->cancelledMandate('mandate-1');
        $world->activeMandateOf($account, 'mandate-2');

        $view = self::handler($world)(new GetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));

        static::assertSame('account-1', $view->accountId);
        static::assertSame('mandate-2', $view->mandate?->mandateId);
        static::assertSame('notified', $view->mandate->state);
        static::assertSame([], $world->journal->entries());
    }

    public function testTheMostRecentMandateIsShownWhenNoneIsActive(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->cancelledMandate('mandate-1');
        $world->cancelledMandate('mandate-2');

        $view = self::handler($world)(new GetAccount(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-1'))));

        static::assertSame('mandate-2', $view->mandate?->mandateId);
        static::assertSame('cancelled', $view->mandate->state);
    }

    public function testNoMandateIsShownWithoutOne(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate('mandate-other', AccountWorld::OTHER_SIRET);

        $view = self::handler($world)(new GetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));

        static::assertNull($view->mandate);
        static::assertTrue($view->hasBirthDate);
        static::assertSame(AccountWorld::SIRET, $view->siret);
    }

    public function testAnAccountOfAnotherTenantIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        try {
            self::handler($world)(new GetAccount(AccountWorld::tenant('tenant-2'), AccountSelector::byAccountId(AccountId::fromString('account-1'))));
            static::fail('The account belongs to another tenant.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
        }
    }

    public function testAnUntrackedSiretIsNotFound(): void
    {
        $world = new AccountWorld();

        $this->expectException(RecordNotFound::class);

        self::handler($world)(new GetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret())));
    }

    private static function handler(AccountWorld $world): GetAccountHandler
    {
        return new GetAccountHandler($world->locator(), $world->mandates);
    }
}
