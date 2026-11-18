<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Scheduler\UrssafScheduleProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The whole public surface of the bundle with every optional part enabled, asserted once as exact sets (ADR 0015):
 * the console commands, the messages Messenger handles and the commands the Scheduler runs.
 */
final class BundleSurfaceTest extends TestCase
{
    private const FULL = [
        'messenger' => ['enabled' => true],
        'outbox' => ['enabled' => true],
        'scheduler' => ['enabled' => true],
    ];

    private const COMMANDS = [
        'urssaf:tdae:account:forget',
        'urssaf:tdae:account:sync',
        'urssaf:tdae:check',
        'urssaf:tdae:declarations:reconcile',
        'urssaf:tdae:declarations:resolve',
        'urssaf:tdae:declare',
        'urssaf:tdae:estimate',
        'urssaf:tdae:mandate:cancel',
        'urssaf:tdae:mandate:import',
        'urssaf:tdae:mandate:notify',
        'urssaf:tdae:mandate:record-cancellation',
        'urssaf:tdae:outbox:purge',
        'urssaf:tdae:outbox:relay',
        'urssaf:tdae:pay',
        'urssaf:tdae:payments:reconcile',
        'urssaf:tdae:payments:resolve',
        'urssaf:tdae:requeue',
        'urssaf:tdae:sepa:list',
        'urssaf:tdae:sepa:register',
        'urssaf:tdae:sepa:revoke',
    ];

    public function testTheBundleProvidesExactlyTheDocumentedCommands(): void
    {
        $names = [];
        $harness = Harness::boot(self::FULL, true);
        foreach ((new Application($harness->kernel))->all('urssaf:tdae') as $key => $command) {
            $name = $command->getName();
            if ($name !== null && $key === $name) {
                $names[] = $name;
            }
        }
        sort($names);

        static::assertCount(20, $names);
        static::assertSame(self::COMMANDS, $names);
    }

    public function testMessengerHandlesTheTwoAccountSyncMessagesAndNothingElse(): void
    {
        $handled = [];
        foreach ((new TestKernel(self::FULL, true))->definitions()->findTaggedServiceIds('messenger.message_handler') as $id => $tags) {
            static::assertNotSame([], $tags);
            if (!str_starts_with($id, 'TheBeardedBear\\UrssafTierceDeclarationAutoEntrepreneur\\')) {
                continue;
            }
            static::assertTrue(class_exists($id));
            $type = (new ReflectionMethod($id, '__invoke'))->getParameters()[0]->getType();
            static::assertInstanceOf(ReflectionNamedType::class, $type);
            $handled[] = $type->getName();
        }
        sort($handled);

        // Nothing ever calls 030, 031, 050, 060, 070 or 071 from a message (ADR 0003, 0015 item 9).
        static::assertSame([SyncAccountMessage::class, SyncAccountsMessage::class], $handled);
    }

    public function testTheSchedulerRunsOnlyExistingReadOnlyCommands(): void
    {
        $scheduled = array_merge(...array_values(UrssafScheduleProvider::TASKS));
        sort($scheduled);

        static::assertSame([
            'urssaf:tdae:account:sync',
            'urssaf:tdae:declarations:reconcile',
            'urssaf:tdae:outbox:purge',
            'urssaf:tdae:outbox:relay',
            'urssaf:tdae:payments:reconcile',
        ], $scheduled);
        static::assertSame([], array_diff($scheduled, self::COMMANDS));
    }
}
