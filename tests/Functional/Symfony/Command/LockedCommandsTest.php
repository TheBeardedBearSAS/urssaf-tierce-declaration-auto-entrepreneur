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
use Symfony\Component\Console\Output\BufferedOutput;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The locks of the commands (ADR 0015 item 7): a command whose lock is held by another process skips its operation and
 * exits with 0; the checks and the confirmation come before the lock, the hidden prompts after it; one key per tenant and
 * SIRET, never the SIRET in clear.
 *
 * @mago-expect lint:too-many-methods One test per lock rule.
 */
final class LockedCommandsTest extends TestCase
{
    private static function mandateLock(string $tenant = 'tenant-1', string $siret = Scenario::SIRET): string
    {
        return OperationLocks::resource(OperationLocks::MANDATE, \sprintf('%s.%s', $tenant, substr(hash('sha256', $siret), 0, 32)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('mandateCommands')]
    public function testTheMandateCommandsSkipTheirOperationWhileTheLockOfTheSiretIsHeld(string $name, array $arguments): void
    {
        $harness = Harness::boot([], false, true);
        $held = HeldLocks::hold($harness, self::mandateLock());
        $command = $harness->command($name);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, ...$arguments], ['interactive' => false]));

        static::assertSame(\sprintf("Skipped: \"%s\" is held by another process.\n", self::mandateLock()), $command->getDisplay());
        static::assertStringNotContainsString(Scenario::SIRET, $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_030) + $harness->urssaf->calls(HttpScript::ROUTE_031) + $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
        $held->release();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function mandateCommands(): iterable
    {
        yield 'notify' => ['urssaf:tdae:mandate:notify', []];
        yield 'cancel' => ['urssaf:tdae:mandate:cancel', ['--force' => true]];
        yield 'import' => ['urssaf:tdae:mandate:import', []];
        yield 'record-cancellation' => ['urssaf:tdae:mandate:record-cancellation', []];
        yield 'forget' => ['urssaf:tdae:account:forget', ['--force' => true]];
    }

    public function testTheErasureByAccountIdTakesTheLockOfTheSiret(): void
    {
        $harness = Harness::boot([], false, true);
        $account = Scenario::trackedAccount($harness);
        $held = HeldLocks::hold($harness, self::mandateLock());
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(0, $command->execute(['--account-id' => $account->accountId, '--force' => true], ['interactive' => false]));

        static::assertStringContainsString('Skipped: "' . self::mandateLock() . '" is held by another process.', $command->getDisplay());
        $held->release();
        static::assertSame(0, $command->execute(['--account-id' => $account->accountId, '--force' => true], ['interactive' => false]));
        static::assertStringContainsString('Erased from the local store only', $command->getDisplay());
    }

    public function testTheLockIsPerTenantAndPerSiret(): void
    {
        $harness = Harness::boot(Fields::secondTenant(), false, true);
        Scenario::trackedAccount($harness, 'tenant-2');
        Scenario::trackedAccount($harness, 'tenant-1', SimulatorSamples::OTHER_SIRET);
        $heldOtherTenant = HeldLocks::hold($harness, self::mandateLock('tenant-1'));
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--tenant' => 'tenant-2'], ['interactive' => false]));
        static::assertSame(0, $command->execute(['siret' => SimulatorSamples::OTHER_SIRET, '--tenant' => 'tenant-1'], ['interactive' => false]));

        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_030));
        $heldOtherTenant->release();
    }

    public function testTheHiddenPromptComesAfterTheLock(): void
    {
        $harness = Harness::boot([], false, true);
        $held = HeldLocks::hold($harness, self::mandateLock());
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('Skipped: ', $command->getDisplay());
        static::assertStringNotContainsString('hidden prompt', $command->getDisplay());
        $held->release();
        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
        static::assertStringContainsString('hidden prompt', $command->getDisplay());
    }

    public function testTheConfirmationComesBeforeTheLock(): void
    {
        $harness = Harness::boot([], false, true);
        Scenario::mandatedAccount($harness);
        $held = HeldLocks::hold($harness, self::mandateLock());
        $command = $harness->command('urssaf:tdae:mandate:cancel');
        $command->setInputs(['no']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Aborted: nothing was sent to URSSAF.', $command->getDisplay());
        static::assertStringNotContainsString('Skipped', $command->getDisplay());
        static::assertSame(2, $harness->command('urssaf:tdae:mandate:cancel')->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
        $held->release();
    }

    public function testTheChecksComeBeforeTheLock(): void
    {
        $harness = Harness::boot([], false, true);
        $held = HeldLocks::hold($harness, self::mandateLock());
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(2, $command->execute(['siret' => 'not-a-siret'], ['interactive' => false]));

        static::assertStringNotContainsString('Skipped', $command->getDisplay());
        $held->release();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('globalCommands')]
    public function testTheCrossTenantCommandsTakeOneGlobalLock(string $name, string $resource, array $arguments): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true, 'sink' => 'test.sink']], false, true);
        $held = HeldLocks::hold($harness, $resource);
        $command = $harness->command($name);

        static::assertSame(0, $command->execute($arguments, ['interactive' => false]));

        static::assertSame(\sprintf("Skipped: \"%s\" is held by another process.\n", $resource), $command->getDisplay());
        $held->release();
        static::assertStringNotContainsString('Skipped', self::display($harness, $name, $arguments));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function display(Harness $harness, string $name, array $arguments): string
    {
        $command = $harness->command($name);
        $command->execute($arguments, ['interactive' => false]);

        return $command->getDisplay();
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function globalCommands(): iterable
    {
        yield 'declarations:reconcile' => ['urssaf:tdae:declarations:reconcile', 'urssaf_tdae.declarations.reconcile.all', []];
        yield 'payments:reconcile' => ['urssaf:tdae:payments:reconcile', 'urssaf_tdae.payments.reconcile.all', []];
        yield 'outbox:relay' => ['urssaf:tdae:outbox:relay', 'urssaf_tdae.outbox.relay.all', []];
        yield 'outbox:purge' => ['urssaf:tdae:outbox:purge', 'urssaf_tdae.outbox.purge.all', []];
    }

    public function testTheRequeueTakesTheLockOfTheTenant(): void
    {
        $harness = Harness::boot([], false, true);
        $held = HeldLocks::hold($harness, 'urssaf_tdae.requeue.tenant-1');
        $command = $harness->command('urssaf:tdae:requeue');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertSame("Skipped: \"urssaf_tdae.requeue.tenant-1\" is held by another process.\n", $command->getDisplay());
        $held->release();
        static::assertStringContainsString('Requeued', self::display($harness, 'urssaf:tdae:requeue', []));
    }

    public function testTheDryRunOfTheReconciliationNeedsNoLock(): void
    {
        $harness = Harness::boot([], false, true);
        $held = HeldLocks::hold($harness, 'urssaf_tdae.declarations.reconcile.all');
        $command = $harness->command('urssaf:tdae:declarations:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));

        static::assertStringContainsString('Dry run: 0 record(s) would be processed', $command->getDisplay());
        $held->release();
    }

    public function testTheSubmissionsAndTheStatelessCommandsTakeNoLock(): void
    {
        $harness = Harness::boot([], false, true);
        Scenario::importedMandate($harness);
        $held = HeldLocks::hold($harness, self::mandateLock());

        $declare = $harness->command('urssaf:tdae:declare');
        static::assertSame(0, $declare->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('declaration.json')], ['interactive' => false]));
        $pay = $harness->command('urssaf:tdae:pay');
        static::assertSame(0, $pay->execute(['siret' => Scenario::SIRET, 'payment-reference' => Scenario::PAYMENT, '--payload' => Fields::payload('payment.json')], ['interactive' => false]));
        $estimate = $harness->command('urssaf:tdae:estimate');
        static::assertSame(0, $estimate->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('turnover.json')], ['interactive' => false]));
        $register = $harness->command('urssaf:tdae:sepa:register');
        static::assertSame(0, $register->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('sepa_mandate.json')], ['interactive' => false]));
        $list = $harness->command('urssaf:tdae:sepa:list');
        static::assertSame(0, $list->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
        $revoke = $harness->command('urssaf:tdae:sepa:revoke');
        static::assertSame(0, $revoke->execute(['siret' => Scenario::SIRET, 'rum' => 'T1234567891234567891234568912345678'], ['interactive' => false]));

        foreach ([$declare, $pay, $estimate, $register, $list, $revoke] as $tester) {
            static::assertStringNotContainsString('Skipped', $tester->getDisplay());
        }
        $held->release();
    }

    public function testWithoutALockFactoryEverythingRunsUnlocked(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
    }

    public function testTheLocksOfAnAccountIdAreThoseOfItsSiret(): void
    {
        $harness = Harness::boot([], false, true);
        $account = Scenario::trackedAccount($harness);
        $locks = $harness->privateService(CommandLocks::class, CommandLocks::class);
        $output = new BufferedOutput();
        $held = HeldLocks::hold($harness, self::mandateLock());

        $busy = $locks->forAccount($output, SimulatorSamples::tenant(), AccountId::fromString($account->accountId), OperationLocks::MANDATE, static fn(): int => 5);
        $held->release();
        $free = $locks->forAccount($output, SimulatorSamples::tenant(), AccountId::fromString($account->accountId), OperationLocks::MANDATE, static fn(): int => 5);

        static::assertSame(0, $busy);
        static::assertSame(5, $free);
        static::assertStringContainsString('Skipped: ', $output->fetch());
    }

    public function testAnUnknownAccountHasNoLock(): void
    {
        $harness = Harness::boot([], false, true);
        $locks = $harness->privateService(CommandLocks::class, CommandLocks::class);

        $this->expectException(RecordNotFound::class);

        $locks->forAccount(new BufferedOutput(), SimulatorSamples::tenant(), AccountId::fromString('unknown'), OperationLocks::MANDATE, static fn(): int => 5);
    }
}
