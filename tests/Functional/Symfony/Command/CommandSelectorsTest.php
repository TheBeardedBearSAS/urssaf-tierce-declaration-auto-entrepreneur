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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationKey;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandSelectors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;

final class CommandSelectorsTest extends TestCase
{
    /**
     * @param callable(Command): void $declare
     * @param array<string, mixed>    $values
     */
    private static function input(callable $declare, array $values): ArrayInput
    {
        $command = new Command('probe');
        $declare($command);

        return new ArrayInput($values, $command->getDefinition());
    }

    public function testTheSiretIsRead(): void
    {
        $input = self::input(CommandSelectors::addSiret(...), ['siret' => '73282932000074']);

        static::assertSame('73282932000074', CommandSelectors::siret($input)->toString());
    }

    public function testAnInvalidSiretIsRefused(): void
    {
        $this->expectException(InvalidValue::class);

        CommandSelectors::siret(self::input(CommandSelectors::addSiret(...), ['siret' => '7328']));
    }

    public function testTheSiretArgumentIsRequired(): void
    {
        $command = new Command('probe');
        CommandSelectors::addSiret($command);

        static::assertTrue($command->getDefinition()->getArgument('siret')->isRequired());
    }

    public function testAnAccountIsSelectedBySiretOrById(): void
    {
        $bySiret = CommandSelectors::accountSelector(self::input(CommandSelectors::addAccountSelector(...), ['siret' => '73282932000074']));
        $byId = CommandSelectors::accountSelector(self::input(CommandSelectors::addAccountSelector(...), ['--account-id' => 'account-1']));

        static::assertInstanceOf(Siret::class, $bySiret->reference);
        static::assertInstanceOf(AccountId::class, $byId->reference);
        static::assertSame('account-1', $byId->reference->value());
    }

    public function testAnAccountSelectorNeedsExactlyOneReference(): void
    {
        foreach ([[], ['siret' => '73282932000074', '--account-id' => 'account-1']] as $values) {
            try {
                CommandSelectors::accountSelector(self::input(CommandSelectors::addAccountSelector(...), $values));
                static::fail('The selector is invalid.');
            } catch (InvalidOptionException $failure) {
                static::assertSame('Give one of the SIRET argument or --account-id.', $failure->getMessage());
            }
        }
    }

    public function testADeclarationIsSelectedByItsSiretAndPeriodOrByItsRecordId(): void
    {
        $current = CommandSelectors::declarationSelector(self::input(CommandSelectors::addDeclarationSelector(...), ['siret' => '73282932000074', '--period' => '2026-T2']));
        $byId = CommandSelectors::declarationSelector(self::input(CommandSelectors::addDeclarationSelector(...), ['--record-id' => 'record-1']));

        static::assertInstanceOf(DeclarationKey::class, $current->reference);
        static::assertSame('73282932000074', $current->reference->siret->toString());
        static::assertSame(2026, $current->reference->period->year());
        static::assertInstanceOf(DeclarationRecordId::class, $byId->reference);
        static::assertSame('record-1', $byId->reference->value());
    }

    public function testAPaymentIsSelectedByItsReferenceOrByItsRecordId(): void
    {
        $byReference = CommandSelectors::paymentSelector(self::input(CommandSelectors::addPaymentSelector(...), ['payment-reference' => 'pay-1']));
        $byId = CommandSelectors::paymentSelector(self::input(CommandSelectors::addPaymentSelector(...), ['--record-id' => 'record-1']));

        static::assertInstanceOf(PaymentReference::class, $byReference->reference);
        static::assertSame('pay-1', $byReference->reference->value());
        static::assertInstanceOf(PaymentRecordId::class, $byId->reference);
    }

    public function testTheSelectorsDeclareTheirArgumentsAndOptionsAsOptional(): void
    {
        $command = new Command('probe');
        CommandSelectors::addDeclarationSelector($command);
        CommandSelectors::addPaymentSelector($command);

        static::assertFalse($command->getDefinition()->getArgument('siret')->isRequired());
        static::assertFalse($command->getDefinition()->getArgument('payment-reference')->isRequired());
        static::assertTrue($command->getDefinition()->getOption('period')->isValueRequired());
        static::assertTrue($command->getDefinition()->getOption('record-id')->isValueRequired());
    }
}
