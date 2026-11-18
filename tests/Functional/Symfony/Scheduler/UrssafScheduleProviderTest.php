<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Schedule;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Scheduler\UrssafScheduleProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;

final class UrssafScheduleProviderTest extends TestCase
{
    public function testEveryCronOfThePackageIsScheduledAtItsFrequency(): void
    {
        $schedule = (new UrssafScheduleProvider([
            'sync_accounts' => 'PT1H',
            'reconcile_declarations' => 'PT15M',
            'reconcile_payments' => 'PT20M',
            'relay_outbox' => 'PT1M',
            'purge_outbox' => 'P1D',
        ]))->getSchedule();

        static::assertSame([
            'urssaf:tdae:account:sync every 3600 seconds (PT1H)',
            'urssaf:tdae:declarations:reconcile every 900 seconds (PT15M)',
            'urssaf:tdae:payments:reconcile every 1200 seconds (PT20M)',
            'urssaf:tdae:outbox:relay every 60 seconds (PT1M)',
            'urssaf:tdae:outbox:purge every 86400 seconds (P1D)',
        ], self::describe($schedule));
    }

    public function testATaskWithoutFrequencyIsLeftOut(): void
    {
        $schedule = (new UrssafScheduleProvider([
            'sync_accounts' => null,
            'reconcile_declarations' => 'PT30M',
            'relay_outbox' => null,
        ]))->getSchedule();

        static::assertSame(['urssaf:tdae:declarations:reconcile every 1800 seconds (PT30M)'], self::describe($schedule));
    }

    public function testAnUnknownOrMissingTaskIsIgnored(): void
    {
        static::assertSame([], self::describe((new UrssafScheduleProvider([]))->getSchedule()));
        static::assertSame([], self::describe((new UrssafScheduleProvider(['unknown_task' => 'PT1M']))->getSchedule()));
    }

    public function testTheCommandsAreTheOnesOfTheBundleAndNeverAWrite(): void
    {
        $commands = array_merge(...array_values(UrssafScheduleProvider::TASKS));

        foreach ($commands as $command) {
            static::assertStringStartsWith('urssaf:tdae:', $command);
        }
        $writes = ['requeue', 'declare', 'pay', 'mandate:notify', 'mandate:cancel', 'mandate:import', 'sepa:register', 'sepa:revoke', 'account:forget'];
        static::assertSame([], array_intersect($commands, array_map(static fn(string $name): string => 'urssaf:tdae:' . $name, $writes)));
        static::assertSame(['sync_accounts', 'reconcile_declarations', 'reconcile_payments', 'relay_outbox', 'purge_outbox'], array_keys(UrssafScheduleProvider::TASKS));
    }

    public function testTheProviderIsRegisteredUnderTheConfiguredScheduleName(): void
    {
        $enabled = (new TestKernel(['scheduler' => ['enabled' => true, 'name' => 'billing', 'frequencies' => ['sync_accounts' => 'PT30M', 'reconcile_declarations' => null]]]))->definitions();
        $disabled = (new TestKernel())->definitions();

        $definition = $enabled->getDefinition(UrssafScheduleProvider::class);
        static::assertSame(['scheduler.schedule_provider' => [['name' => 'billing']]], $definition->getTags());
        static::assertEquals([['sync_accounts' => 'PT30M', 'reconcile_declarations' => null, 'reconcile_payments' => 'PT15M', 'relay_outbox' => null, 'purge_outbox' => null]], $definition->getArguments());
        static::assertFalse($disabled->hasDefinition(UrssafScheduleProvider::class));
    }

    public function testTheOutboxCommandsAreScheduledOnlyWithTheOutbox(): void
    {
        $kernel = new TestKernel(['scheduler' => ['enabled' => true], 'outbox' => ['enabled' => true]]);

        $definition = $kernel->definitions()->getDefinition(UrssafScheduleProvider::class);

        static::assertSame(['scheduler.schedule_provider' => [['name' => 'urssaf_tdae']]], $definition->getTags());
        static::assertEquals([['sync_accounts' => 'P1D', 'reconcile_declarations' => 'PT15M', 'reconcile_payments' => 'PT15M', 'relay_outbox' => 'PT1M', 'purge_outbox' => 'P1D']], $definition->getArguments());
    }

    /**
     * @return list<string> "<command> <trigger>" of every recurring message
     */
    private static function describe(Schedule $schedule): array
    {
        $described = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('urssaf_tdae', $recurring->getId(), $recurring->getTrigger(), new DateTimeImmutable());
            $messages = iterator_to_array($recurring->getProvider()->getMessages($context), false);
            static::assertCount(1, $messages);
            $message = reset($messages);
            static::assertInstanceOf(RunCommandMessage::class, $message);
            $described[] = $message->input . ' ' . $recurring->getTrigger()->__toString();
        }

        return $described;
    }
}
