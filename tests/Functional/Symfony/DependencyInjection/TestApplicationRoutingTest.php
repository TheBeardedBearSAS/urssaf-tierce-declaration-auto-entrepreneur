<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The test application routes the messages of the bundle by namespace to its in-memory "async" transport (the tests of
 * the commands and of the handlers rely on it).
 */
final class TestApplicationRoutingTest extends TestCase
{
    public function testTheMessagesOfTheBundleAreRoutedToTheAsyncTransport(): void
    {
        $harness = Harness::boot(['messenger' => ['enabled' => true]], true);
        $bus = $harness->privateService('messenger.default_bus', MessageBusInterface::class);

        $bus->dispatch(new SyncAccountsMessage('tenant-1'));
        $bus->dispatch(new SyncAccountMessage('tenant-1', 'account-1'));

        $transport = $harness->privateService('messenger.transport.async', InMemoryTransport::class);
        static::assertCount(2, $transport->getSent());
        static::assertInstanceOf(SyncAccountsMessage::class, $transport->getSent()[0]->getMessage());
        static::assertInstanceOf(SyncAccountMessage::class, $transport->getSent()[1]->getMessage());
    }
}
