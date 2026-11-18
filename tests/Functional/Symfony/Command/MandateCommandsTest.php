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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The mandate commands: notify (030), cancel (031), import and record-cancellation (no call).
 *
 * @mago-expect lint:too-many-methods One test per behaviour of four commands.
 */
final class MandateCommandsTest extends TestCase
{
    private const UUID = '/^[0-9a-f-]{36}$/';

    public function testTheMandateIsNotifiedWithTheBirthDateOfTheTrackedAccount(): void
    {
        $harness = Harness::boot();
        $account = Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertMatchesRegularExpression(self::UUID, $fields['Mandate id']);
        unset($fields['Mandate id']);
        static::assertSame([
            'Account id' => $account->accountId,
            'Origin' => 'notified',
            'State' => 'notified',
            'Cancellation origin' => '-',
            'Notified at' => '2026-11-15T10:00:00Z',
            'Cancelled at' => '-',
        ], $fields);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_030));
    }

    public function testTheBirthDateOfAnUntrackedAutoEntrepreneurIsAskedInAHiddenPrompt(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:notify');
        $command->setInputs([SimulatorSamples::BIRTH_DATE]);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD): ', $command->getDisplay());
        static::assertStringNotContainsString(SimulatorSamples::BIRTH_DATE, $command->getDisplay());
        static::assertSame('-', Fields::of($command->getDisplay())['Account id']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_030));
    }

    public function testTheBirthDateIsAskedWhenTheTrackedAccountDoesNotHoldIt(): void
    {
        $harness = Harness::boot();
        $harness->get(TrackAccountHandler::class)(new TrackAccount(TenantId::fromString('tenant-1'), Siret::fromString(Scenario::SIRET), null));
        $command = $harness->command('urssaf:tdae:mandate:notify');
        $command->setInputs([SimulatorSamples::BIRTH_DATE]);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD): ', $command->getDisplay());
    }

    public function testWithoutABirthDateToAskTheRunIsRefusedBeforeAnyCall(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD) is read from a hidden prompt: run the command interactively.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_030));
    }

    public function testAnInvalidBirthDateIsRefusedWithoutBeingRepeated(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:notify');
        $command->setInputs(['23/02/1975']);

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Invalid calendar_date: the value does not match the expected format (Y-m-d). (calendar_date.invalid_format)', $command->getDisplay());
        static::assertStringNotContainsString('23/02/1975', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_030));
    }

    public function testAnInvalidSiretIsRefusedWithoutBeingRepeated(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(2, $command->execute(['siret' => '12345'], ['interactive' => false]));

        static::assertStringContainsString('siret.invalid_format', $command->getDisplay());
        static::assertStringNotContainsString('12345', $command->getDisplay());
    }

    public function testTheTenantIsRequiredWithSeveralConfiguredTenants(): void
    {
        $harness = Harness::boot(Fields::secondTenant());
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('The --tenant option is required (configured tenants: 2).', $command->getDisplay());
    }

    public function testAnotherTenantIsSelectedWithTheOption(): void
    {
        $harness = Harness::boot(Fields::secondTenant());
        Scenario::trackedAccount($harness, 'tenant-2');
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--tenant' => 'tenant-2'], ['interactive' => false]));

        static::assertSame('tenant-2', $harness->get(GetAccountHandler::class)(new GetAccount(TenantId::fromString('tenant-2'), AccountSelector::bySiret(SimulatorSamples::siret())))->tenantId);
    }

    public function testAnUrssafFailureIsAFailure(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_030, 400, '{"message":"refused"}');
        $command = $harness->command('urssaf:tdae:mandate:notify');

        static::assertSame(1, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
    }

    public function testTheMandateIsCancelledAfterAConfirmation(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');
        $command->setInputs(['yes']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Cancel the mandate of this auto-entrepreneur at URSSAF? [y/N]', $command->getDisplay());
        static::assertStringNotContainsString(Scenario::SIRET, $command->getDisplay());
        $fields = Fields::of($command->getDisplay());
        static::assertSame('cancelled', $fields['State']);
        static::assertSame('2026-11-15T10:00:00Z', $fields['Cancelled at']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testTheCancellationIsAbortedWithoutConfirmation(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');
        $command->setInputs(['no']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Aborted: nothing was sent to URSSAF.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testAnEmptyAnswerIsNotAConfirmation(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');
        $command->setInputs(['']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Aborted: nothing was sent to URSSAF.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testForceSkipsTheConfirmation(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--force' => true], ['interactive' => false]));

        static::assertStringNotContainsString('[y/N]', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testTheCancellationNeedsAConfirmationWhenNotInteractive(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('Cancelling a mandate needs a confirmation: use --force when not interactive.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testTheBirthDateOfTheCancellationIsAskedWhenTheAccountIsNotTracked(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');
        $command->setInputs([SimulatorSamples::BIRTH_DATE]);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--force' => true]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD): ', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testCancellingWithoutAMandateIsAnInvalidUsage(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:cancel');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, '--force' => true], ['interactive' => false]));

        static::assertStringContainsString('mandate', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testAMandateIsImportedWithoutAnyCallAndWithoutAskingTheStoredBirthDate(): void
    {
        $harness = Harness::boot();
        $account = Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:import');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame('imported', $fields['Origin']);
        static::assertSame('notified', $fields['State']);
        static::assertSame($account->accountId, $fields['Account id']);
        static::assertStringContainsString('Recorded without any call: nothing was sent to URSSAF.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_030));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testAnEmptyBirthDateAnswerImportsTheMandateWithoutOne(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:import');
        $command->setInputs(['']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD): ', $command->getDisplay());
        static::assertSame('-', Fields::of($command->getDisplay())['Account id']);
    }

    public function testTheBirthDateGivenToTheImportIsStoredOnTheTrackedAccount(): void
    {
        $harness = Harness::boot();
        $harness->get(TrackAccountHandler::class)(new TrackAccount(TenantId::fromString('tenant-1'), Siret::fromString(Scenario::SIRET), null));
        $command = $harness->command('urssaf:tdae:mandate:import');
        $command->setInputs([SimulatorSamples::BIRTH_DATE]);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertTrue($harness->get(GetAccountHandler::class)(new GetAccount(TenantId::fromString('tenant-1'), AccountSelector::bySiret(SimulatorSamples::siret())))->hasBirthDate);
    }

    public function testTheImportAsksAnInteractiveAnswerForAnUntrackedAccount(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:import');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('Birth date (YYYY-MM-DD) is read from a hidden prompt: run the command interactively.', $command->getDisplay());
    }

    public function testTheCancellationOfTheLatestMandateIsRecordedWithoutAnyCall(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:mandate:record-cancellation');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame('cancelled', $fields['State']);
        static::assertSame('2026-11-15T10:00:00Z', $fields['Cancelled at']);
        static::assertStringContainsString('Recorded without any call: nothing was sent to URSSAF.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_031));
    }

    public function testRecordingTheCancellationOfAnUnknownMandateIsAnInvalidUsage(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:mandate:record-cancellation');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
    }
}
