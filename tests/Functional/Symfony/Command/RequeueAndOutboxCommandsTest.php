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

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\PurgeOutboxCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\ThrowingListener;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;

/**
 * The requeue command and the outbox commands (relay, purge).
 *
 * @mago-expect lint:too-many-methods One test per behaviour of three commands.
 */
final class RequeueAndOutboxCommandsTest extends TestCase
{
    /**
     * The SIRET of a second auto-entrepreneur (no tracked account: the checks of the mandate are skipped).
     */
    private const OTHER_SIRET = '12345678901234';

    /**
     * Two declarations and one payment refused for invalid credentials (the token route answers a 401).
     */
    private static function refusedRecords(Harness $harness): void
    {
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 401, '{"error":"invalid_client"}');
        foreach ([Scenario::SIRET, self::OTHER_SIRET] as $siret) {
            $declare = $harness->command('urssaf:tdae:declare');
            $declare->execute(['siret' => $siret, '--payload' => Fields::payload('declaration.json'), '--skip-mandate-check' => true], ['interactive' => false]);
        }
        $pay = $harness->command('urssaf:tdae:pay');
        $pay->execute(['siret' => Scenario::SIRET, 'payment-reference' => Scenario::PAYMENT, '--payload' => Fields::payload('payment.json'), '--skip-mandate-check' => true], ['interactive' => false]);
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 200, Stack::TOKEN_RESPONSE);
    }

    public function testTheRecordsRefusedForInvalidCredentialsAreRequeued(): void
    {
        $harness = Harness::boot();
        self::refusedRecords($harness);
        $command = $harness->command('urssaf:tdae:requeue');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertSame([['Records', 'Requeued'], ['declarations', '2'], ['payments', '1']], Output::rows($command->getDisplay()));
        static::assertStringContainsString('The requeued records are pending: send them again with urssaf:tdae:declare and urssaf:tdae:pay.', $command->getDisplay());
    }

    public function testTheLimitAppliesToEachKind(): void
    {
        $harness = Harness::boot();
        self::refusedRecords($harness);
        $command = $harness->command('urssaf:tdae:requeue');

        static::assertSame(0, $command->execute(['--limit' => '1'], ['interactive' => false]));

        static::assertSame([['Records', 'Requeued'], ['declarations', '1'], ['payments', '1']], Output::rows($command->getDisplay()));
    }

    public function testAnInvalidLimitOfTheRequeueIsRefused(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:requeue');

        static::assertSame(2, $command->execute(['--limit' => 'many'], ['interactive' => false]));
    }

    public function testNothingToRequeueGivesZeroes(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:requeue');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertSame([['Records', 'Requeued'], ['declarations', '0'], ['payments', '0']], Output::rows($command->getDisplay()));
    }

    public function testThePendingEventsAreRelayed(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]);
        Scenario::pendingOutboxEntry($harness);
        $command = $harness->command('urssaf:tdae:outbox:relay');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertSame([['Dispatched', 'Failed', 'Deferred'], ['1', '0', '0']], Output::rows($command->getDisplay()));
        static::assertCount(1, $harness->privateService('test.sink', RecordingIntegrationEventSink::class)->deliveredIds());
        static::assertSame([], $harness->privateService(Outbox::class, Outbox::class)->pending(10));
    }

    public function testTheLimitBoundsTheEventsRelayed(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]);
        Scenario::importedMandate($harness);
        Scenario::importedMandate($harness, 'tenant-1', '12345678901234');
        $command = $harness->command('urssaf:tdae:outbox:relay');

        static::assertSame(0, $command->execute(['--limit' => '1'], ['interactive' => false]));

        static::assertSame([['Dispatched', 'Failed', 'Deferred'], ['1', '0', '0']], Output::rows($command->getDisplay()));
        static::assertCount(1, $harness->privateService(Outbox::class, Outbox::class)->pending(10));
    }

    public function testAFailedDeliveryIsAFailure(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'event_dispatcher']]);
        $harness->privateService('event_dispatcher', EventDispatcherInterface::class)->addListener(MandateNotified::class, new ThrowingListener());
        Scenario::pendingOutboxEntry($harness);
        $command = $harness->command('urssaf:tdae:outbox:relay');

        static::assertSame(1, $command->execute([], ['interactive' => false]));

        static::assertSame([['Dispatched', 'Failed', 'Deferred'], ['0', '1', '0']], Output::rows($command->getDisplay()));
        static::assertStringNotContainsString('listener exploded', $command->getDisplay());
    }

    public function testAnInvalidLimitOfTheRelayIsRefused(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]);
        $command = $harness->command('urssaf:tdae:outbox:relay');

        static::assertSame(2, $command->execute(['--limit' => '-1'], ['interactive' => false]));
    }

    public function testTheDispatchedEntriesAreDeletedAfterTheRetentionOfTheConfiguration(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink', 'retention' => 'P7D']]);
        Scenario::importedMandate($harness);
        $harness->command('urssaf:tdae:outbox:relay')->execute([], ['interactive' => false]);
        $command = $harness->command('urssaf:tdae:outbox:purge');

        static::assertSame(0, $command->execute([], ['interactive' => false]));
        static::assertSame("0 dispatched outbox entries deleted.\n", $command->getDisplay());

        $harness->clock->modify('+8 days');
        static::assertSame(0, $command->execute([], ['interactive' => false]));
        static::assertSame("1 dispatched outbox entries deleted.\n", $command->getDisplay());
    }

    public function testTheRetentionOfTheRunOverridesTheConfiguration(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink', 'retention' => 'P7D']]);
        Scenario::importedMandate($harness);
        $harness->command('urssaf:tdae:outbox:relay')->execute([], ['interactive' => false]);
        $harness->clock->modify('+2 days');
        $command = $harness->command('urssaf:tdae:outbox:purge');

        static::assertSame(0, $command->execute(['--older-than' => 'P1D'], ['interactive' => false]));

        static::assertSame("1 dispatched outbox entries deleted.\n", $command->getDisplay());
    }

    #[DataProvider('invalidRetentions')]
    public function testAnInvalidRetentionIsRefusedWithoutBeingRepeated(string $value): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]);
        $command = $harness->command('urssaf:tdae:outbox:purge');

        static::assertSame(2, $command->execute(['--older-than' => $value], ['interactive' => false]));

        static::assertSame("The --older-than option must be an ISO 8601 duration such as P30D.\n", $command->getDisplay());
    }

    public function testAnInvalidRetentionKeepsTheDateIntervalFailureAsItsCause(): void
    {
        $retention = new ReflectionMethod(PurgeOutboxCommand::class, 'retention');

        try {
            $retention->invoke(null, 'not a duration');
            static::fail('An invalid duration must be refused.');
        } catch (InvalidOptionException $failure) {
            static::assertSame(0, $failure->getCode());
            static::assertInstanceOf(Exception::class, $failure->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRetentions(): iterable
    {
        yield 'a word' => ['a-month'];
        yield 'an empty value' => [''];
    }
}
