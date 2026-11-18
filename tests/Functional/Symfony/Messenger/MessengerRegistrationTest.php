<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\SyncAccountMessageHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\SyncAccountsMessageHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;

final class MessengerRegistrationTest extends TestCase
{
    private const MESSENGER = ['messenger' => ['enabled' => true]];

    public function testNothingIsRegisteredWhenMessengerIsDisabled(): void
    {
        $harness = Harness::boot();

        static::assertFalse($harness->hasPrivate(SyncAccountsMessageHandler::class));
        static::assertFalse($harness->hasPrivate(SyncAccountMessageHandler::class));
    }

    public function testBothHandlersAreRegisteredWithTheTag(): void
    {
        $container = (new TestKernel(self::MESSENGER, true))->definitions();

        foreach ([SyncAccountsMessageHandler::class, SyncAccountMessageHandler::class] as $handler) {
            static::assertTrue($container->getDefinition($handler)->isAutowired());
            static::assertTrue($container->getDefinition($handler)->hasTag('messenger.message_handler'));
        }
    }

    public function testBothHandlersAreRegisteredAtRunTime(): void
    {
        $harness = Harness::boot(self::MESSENGER, true);

        static::assertTrue($harness->hasPrivate(SyncAccountsMessageHandler::class));
        static::assertTrue($harness->hasPrivate(SyncAccountMessageHandler::class));
    }

    public function testTheMessagesAreRoutedToTheTransportThroughTheBus(): void
    {
        $harness = Harness::boot(self::MESSENGER, true);
        $bus = $harness->privateService('messenger.default_bus', MessageBusInterface::class);

        $bus->dispatch(new SyncAccountsMessage('tenant-1'));
        $bus->dispatch(new SyncAccountMessage('tenant-1', 'account-1'));

        $sent = $harness->privateService('messenger.transport.async', InMemoryTransport::class)->getSent();
        static::assertCount(2, $sent);
        static::assertInstanceOf(SyncAccountsMessage::class, $sent[0]->getMessage());
        static::assertInstanceOf(SyncAccountMessage::class, $sent[1]->getMessage());
    }

    public function testTheTenantMessageRefreshesTheAccountsOfTheTenant(): void
    {
        $harness = Harness::boot(self::MESSENGER, true);
        Scenario::trackedAccount($harness);

        $harness->privateService(SyncAccountsMessageHandler::class, SyncAccountsMessageHandler::class)(new SyncAccountsMessage('tenant-1'));

        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheAccountMessageRefreshesOneAccount(): void
    {
        $harness = Harness::boot(self::MESSENGER, true);
        $account = Scenario::trackedAccount($harness);

        $harness->privateService(SyncAccountMessageHandler::class, SyncAccountMessageHandler::class)(new SyncAccountMessage('tenant-1', $account->accountId));

        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testNothingButTheTwoSyncMessagesIsHandled(): void
    {
        $container = (new TestKernel(self::MESSENGER, true))->definitions();

        $handled = array_values(array_filter(
            array_keys($container->findTaggedServiceIds('messenger.message_handler')),
            static fn(string $id): bool => str_starts_with($id, 'TheBeardedBear\\UrssafTierceDeclarationAutoEntrepreneur\\Bridge\\Symfony\\'),
        ));

        static::assertSame([SyncAccountsMessageHandler::class, SyncAccountMessageHandler::class], $handled);
    }
}
