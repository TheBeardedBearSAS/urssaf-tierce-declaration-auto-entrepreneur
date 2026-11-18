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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The GDPR erasure from the local store (ADR 0017): nothing is ever sent to URSSAF.
 *
 * @mago-expect lint:too-many-methods One test per behaviour of one command.
 */
final class ForgetAccountCommandTest extends TestCase
{
    private static function stillTracked(Harness $harness): bool
    {
        try {
            $harness->get(GetAccountHandler::class)(new GetAccount(TenantId::fromString('tenant-1'), AccountSelector::bySiret(SimulatorSamples::siret())));

            return true;
        } catch (RecordNotFound) {
            return false;
        }
    }

    public function testAnAccountWithAnActiveMandateIsRefusedWithTheHintOfTheOptIn(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(1, $command->execute(['siret' => Scenario::SIRET, '--force' => true], ['interactive' => false]));

        static::assertStringContainsString('Cancel the mandate first (urssaf:tdae:mandate:cancel), or pass --allow-active-mandate to erase it locally anyway.', $command->getDisplay());
        static::assertTrue(self::stillTracked($harness));
    }

    public function testTheAccountItsMandatesAndItsRecordsAreErasedWithTheOptIn(): void
    {
        $harness = Harness::boot();
        $account = Scenario::mandatedAccount($harness);
        static::assertSame(0, $harness->command('urssaf:tdae:declare')->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('declaration.json'), '--skip-periodicity-check' => true], ['interactive' => false]));
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--allow-active-mandate' => true, '--force' => true], ['interactive' => false]));

        static::assertSame([
            'Account id' => $account->accountId,
            'Mandates erased' => '1',
            'Declarations erased' => '1',
            'Payments erased' => '0',
        ], Fields::of($command->getDisplay()));
        static::assertStringContainsString('Erased from the local store only: nothing was sent to URSSAF.', $command->getDisplay());
        static::assertFalse(self::stillTracked($harness));
    }

    public function testThePaymentsAreErasedToo(): void
    {
        $harness = Harness::boot();
        Scenario::payment($harness);
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--allow-active-mandate' => true, '--force' => true], ['interactive' => false]));

        static::assertSame('1', Fields::of($command->getDisplay())['Payments erased']);
    }

    public function testAnAccountIsErasedByItsId(): void
    {
        $harness = Harness::boot();
        $account = Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(0, $command->execute(['--account-id' => $account->accountId, '--force' => true], ['interactive' => false]));

        static::assertSame($account->accountId, Fields::of($command->getDisplay())['Account id']);
        static::assertFalse(self::stillTracked($harness));
    }

    public function testAnUnknownAccountIdIsAnInvalidUsage(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(2, $command->execute(['--account-id' => 'unknown', '--force' => true], ['interactive' => false]));
    }

    public function testUnknownOutcomesAreRefusedUnlessTheyAreAllowed(): void
    {
        $harness = Harness::boot();
        Scenario::outcomeUnknownDeclaration($harness);
        $command = $harness->command('urssaf:tdae:account:forget');
        $arguments = ['siret' => Scenario::SIRET, '--allow-active-mandate' => true, '--force' => true];

        static::assertSame(1, $command->execute($arguments, ['interactive' => false]));
        static::assertStringContainsString('Resolve the unknown outcomes first (urssaf:tdae:declarations:resolve, urssaf:tdae:payments:resolve), or pass --allow-unknown-outcomes to erase them anyway.', $command->getDisplay());

        static::assertSame(0, $command->execute([...$arguments, '--allow-unknown-outcomes' => true], ['interactive' => false]));
        static::assertSame('1', Fields::of($command->getDisplay())['Declarations erased']);
    }

    public function testTheOptInsOfThePolicyApplyWhenNoFlagIsGiven(): void
    {
        $harness = Harness::boot(['workflow' => ['erasure' => ['allow_active_mandate' => true, 'allow_unknown_outcomes' => true]]]);
        Scenario::outcomeUnknownDeclaration($harness);
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--force' => true], ['interactive' => false]));

        static::assertSame('1', Fields::of($command->getDisplay())['Declarations erased']);
    }

    public function testTheErasureIsConfirmedWithoutShowingTheSiret(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:forget');
        $command->setInputs(['yes']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Erase this auto-entrepreneur, its mandates, declarations and payments from the local store? This cannot be undone and nothing is sent to URSSAF. [y/N]', $command->getDisplay());
        static::assertStringNotContainsString('without being resolved', $command->getDisplay());
        static::assertStringNotContainsString(Scenario::SIRET, $command->getDisplay());
        static::assertFalse(self::stillTracked($harness));
    }

    public function testTheConfirmationWarnsAboutUnknownOutcomes(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:forget');
        $command->setInputs(['no']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--allow-unknown-outcomes' => true]));

        static::assertStringContainsString('nothing is sent to URSSAF. The records whose outcome is unknown are erased without being resolved. [y/N]', $command->getDisplay());
    }

    public function testTheErasureIsAbortedWithoutConfirmation(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:forget');
        $command->setInputs(['no']);

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET]));

        static::assertStringContainsString('Aborted: nothing was erased.', $command->getDisplay());
        static::assertTrue(self::stillTracked($harness));
    }

    public function testTheErasureNeedsAConfirmationWhenNotInteractive(): void
    {
        $harness = Harness::boot();
        Scenario::trackedAccount($harness);
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('Erasing an auto-entrepreneur needs a confirmation: use --force when not interactive.', $command->getDisplay());
        static::assertTrue(self::stillTracked($harness));
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('invalidSelectors')]
    public function testExactlyOneSelectorIsNeeded(array $arguments): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:account:forget');

        static::assertSame(2, $command->execute([...$arguments, '--force' => true], ['interactive' => false]));

        static::assertStringContainsString('Give one of the SIRET argument or --account-id.', $command->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidSelectors(): iterable
    {
        yield 'nothing' => [[]];
        yield 'both' => [['siret' => Scenario::SIRET, '--account-id' => 'x']];
    }
}
