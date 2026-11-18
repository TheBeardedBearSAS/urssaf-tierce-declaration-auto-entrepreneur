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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The 020 commands: track or refresh one SIRET, refresh a tenant, plan it, or dispatch it to Messenger.
 *
 * @mago-expect lint:too-many-methods One test per mode and per rule of the command.
 */
final class SyncAccountsCommandTest extends TestCase
{
    private const OTHER_SIRET = '12345678901234';

    public function testAnUntrackedSiretIsTracked(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $fields['Account id']);
        unset($fields['Account id']);
        static::assertSame([
            'Tenant' => 'tenant-1',
            'SIRET' => Scenario::SIRET,
            'Birth date stored' => 'no',
            'Mandate present' => 'no',
            'Periodicity' => 'T',
            'Affiliation date' => '2010-03-01',
            'ACRE exemption' => 'no',
            'Mandate state' => '-',
            'Last synced at' => '2026-11-15T10:00:00Z',
        ], $fields);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testATrackedSiretIsRefreshed(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $harness->clock->modify('+1 hour');
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame('yes', $fields['Birth date stored']);
        static::assertSame('2026-11-15T11:00:00Z', $fields['Last synced at']);
        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheStateOfTheLocalMandateIsShown(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertSame('notified', Fields::of($command->getDisplay())['Mandate state']);
        static::assertSame('no', Fields::of($command->getDisplay())['Mandate present']);
    }

    public function testTheBirthDateIsAskedAndStoredWithTheOption(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');
        $command->setInputs([SimulatorSamples::BIRTH_DATE]);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--with-birth-date' => true]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD): ', $command->getDisplay());
        static::assertStringNotContainsString(SimulatorSamples::BIRTH_DATE, $command->getDisplay());
        static::assertSame('yes', Fields::of($command->getDisplay())['Birth date stored']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheBirthDateIsStoredOnATrackedAccountThenItIsRefreshed(): void
    {
        $harness = Harness::boot();
        $harness->get(TrackAccountHandler::class)(new TrackAccount(TenantId::fromString('tenant-1'), Siret::fromString(Scenario::SIRET), null));
        $command = $harness->command('urssaf:tdae:account:sync');
        $command->setInputs([SimulatorSamples::BIRTH_DATE]);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--with-birth-date' => true]));

        static::assertSame('yes', Fields::of($command->getDisplay())['Birth date stored']);
        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheBirthDatePromptNeedsAnInteractiveRunAndAValidAnswer(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, '--with-birth-date' => true], ['interactive' => false]));
        static::assertStringContainsString('Birth date (YYYY-MM-DD) is read from a hidden prompt: run the command interactively.', $command->getDisplay());

        $command->setInputs(['1975/02/23']);
        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, '--with-birth-date' => true]));
        static::assertStringContainsString('calendar_date.invalid_format', $command->getDisplay());
        static::assertStringNotContainsString('1975/02/23', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheBirthDateOptionNeedsASiret(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['--with-birth-date' => true], ['interactive' => false]));

        static::assertStringContainsString('--with-birth-date needs a SIRET.', $command->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('batchOptions')]
    public function testASiretCannotBeCombinedWithTheBatchOptions(array $options): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, ...$options], ['interactive' => false]));

        static::assertStringContainsString('A SIRET cannot be combined with --limit, --dry-run or --async.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function batchOptions(): iterable
    {
        yield '--limit' => [['--limit' => '1']];
        yield '--dry-run' => [['--dry-run' => true]];
        yield '--async' => [['--async' => true]];
    }

    public function testAnInvalidSiretIsRefused(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['siret' => '12'], ['interactive' => false]));

        static::assertStringContainsString('siret.invalid_format', $command->getDisplay());
    }

    public function testTheTenantOfASiretIsRequiredWithSeveralConfiguredTenants(): void
    {
        $harness = Harness::boot(Fields::secondTenant());
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('The --tenant option is required (configured tenants: 2).', $command->getDisplay());

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--tenant' => 'tenant-2'], ['interactive' => false]));
        static::assertSame('tenant-2', Fields::of($command->getDisplay())['Tenant']);
    }

    public function testAnUrssafRefusalOfASiretIsAFailure(): void
    {
        $harness = Harness::boot();
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_020, 400, 'error_param_invalide.json');
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(1, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
    }

    public function testTheLeastRecentlySyncedAccountsOfEveryTenantAreRefreshed(): void
    {
        $harness = Harness::boot(Fields::secondTenant());
        Scenario::trackedAccount($harness, 'tenant-1');
        Scenario::trackedAccount($harness, 'tenant-2', self::OTHER_SIRET);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertCount(3, Output::rows($command->getDisplay()));
        static::assertStringContainsString('2 record(s) processed (unchanged: 2).', $command->getDisplay());
        static::assertSame(4, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testOnlyTheTenantOfTheOptionIsRefreshed(): void
    {
        $harness = Harness::boot(Fields::secondTenant());
        Scenario::trackedAccount($harness, 'tenant-1');
        Scenario::trackedAccount($harness, 'tenant-2', self::OTHER_SIRET);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['--tenant' => 'tenant-2'], ['interactive' => false]));

        static::assertStringContainsString('1 record(s) processed (unchanged: 1).', $command->getDisplay());
        static::assertSame(3, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheLimitBoundsTheAccountsRefreshed(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        Scenario::trackedAccount($harness, 'tenant-1', self::OTHER_SIRET);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['--limit' => '1'], ['interactive' => false]));

        static::assertStringContainsString('1 record(s) processed', $command->getDisplay());
    }

    public function testAFailedItemIsAFailure(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_020, 400, 'error_param_invalide.json');
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(1, $command->execute([], ['interactive' => false]));

        static::assertStringContainsString('failed: 1', $command->getDisplay());
        static::assertStringNotContainsString('Un des paramètres', $command->getDisplay());
    }

    public function testADryRunListsTheAccountsAndCallsNothing(): void
    {
        $harness = Harness::boot();
        $account = Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));

        static::assertSame([['Tenant', 'Record'], ['tenant-1', $account->accountId]], Output::rows($command->getDisplay()));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testADryRunHonoursTheLimitAndTheTenant(): void
    {
        $harness = Harness::boot(Fields::secondTenant());
        Scenario::trackedAccount($harness, 'tenant-1');
        Scenario::trackedAccount($harness, 'tenant-1', self::OTHER_SIRET);
        Scenario::trackedAccount($harness, 'tenant-2', '11111111111111');
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['--dry-run' => true, '--limit' => '1', '--tenant' => 'tenant-1'], ['interactive' => false]));

        static::assertCount(2, Output::rows($command->getDisplay()));
        static::assertSame('tenant-1', Output::rows($command->getDisplay())[1][0]);
    }

    public function testAnAsyncRunNeedsMessenger(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['--async' => true], ['interactive' => false]));

        static::assertStringContainsString('--async requires "messenger.enabled".', $command->getDisplay());
    }

    public function testAnAsyncRunDispatchesOneMessagePerTenant(): void
    {
        $harness = Harness::boot([...Fields::secondTenant(), 'messenger' => ['enabled' => true]], true);
        Scenario::trackedAccount($harness, 'tenant-1');
        Scenario::trackedAccount($harness, 'tenant-2', self::OTHER_SIRET);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['--async' => true], ['interactive' => false]));

        static::assertSame("2 message(s) dispatched.\n", $command->getDisplay());
        $sent = array_map(static fn(Envelope $envelope): object => $envelope->getMessage(), $harness->privateService('messenger.transport.async', InMemoryTransport::class)->getSent());
        static::assertEquals([new SyncAccountsMessage('tenant-1'), new SyncAccountsMessage('tenant-2')], $sent);
        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testAnAsyncRunOfOneTenant(): void
    {
        $harness = Harness::boot([...Fields::secondTenant(), 'messenger' => ['enabled' => true]], true);
        Scenario::trackedAccount($harness, 'tenant-1');
        Scenario::trackedAccount($harness, 'tenant-2', self::OTHER_SIRET);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['--async' => true, '--tenant' => 'tenant-2'], ['interactive' => false]));

        static::assertSame("1 message(s) dispatched.\n", $command->getDisplay());
    }

    public function testAnAsyncRunCannotBeADryRun(): void
    {
        $harness = Harness::boot(['messenger' => ['enabled' => true]], true);
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(2, $command->execute(['--async' => true, '--dry-run' => true], ['interactive' => false]));

        static::assertStringContainsString('--async cannot be combined with --dry-run.', $command->getDisplay());
    }

    public function testASiretRefreshIsSkippedWhileItsLockIsHeld(): void
    {
        $harness = Harness::boot([], false, true);
        $held = HeldLocks::hold($harness, OperationLocks::resource(OperationLocks::ACCOUNT_SYNC, OperationLocks::accountScope(SimulatorSamples::tenant(), SimulatorSamples::siret())));
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--with-birth-date' => true], ['interactive' => false]));

        static::assertStringContainsString(\sprintf('Skipped: "urssaf_tdae.account.sync.tenant-1.%s" is held by another process.', substr(hash('sha256', Scenario::SIRET), 0, 32)), $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_020));
        $held->release();
    }

    public function testATenantRefreshIsSkippedWhileItsLockIsHeld(): void
    {
        $harness = Harness::boot([], false, true);
        Scenario::trackedAccount($harness);
        $held = HeldLocks::hold($harness, 'urssaf_tdae.accounts.sync.tenant-1');
        $command = $harness->command('urssaf:tdae:account:sync');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertStringContainsString('Skipped: "urssaf_tdae.accounts.sync.tenant-1" is held by another process.', $command->getDisplay());
        static::assertStringContainsString('0 record(s) processed.', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
        $held->release();
    }
}
