<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;

/**
 * The arguments and options that designate an auto-entrepreneur, a declaration or a payment, and their reading. The
 * values go through the value objects: an invalid one is an invalid input (exit code 2).
 */
final class CommandSelectors
{
    public const ARGUMENT_SIRET = 'siret';
    private const OPTION_ACCOUNT_ID = 'account-id';
    private const OPTION_PERIOD = 'period';
    private const OPTION_RECORD_ID = 'record-id';
    private const ARGUMENT_PAYMENT_REFERENCE = 'payment-reference';

    /**
     * The SIRET argument, required.
     */
    public static function addSiret(Command $command): void
    {
        $command->addArgument(self::ARGUMENT_SIRET, InputArgument::REQUIRED, 'SIRET of the auto-entrepreneur (14 digits)');
    }

    /**
     * @throws InvalidValue for an invalid SIRET
     */
    public static function siret(InputInterface $input): Siret
    {
        return Siret::fromString(CommandOptions::argument($input, self::ARGUMENT_SIRET) ?? '');
    }

    /**
     * [siret] | --account-id=<id>
     */
    public static function addAccountSelector(Command $command): void
    {
        $command->addArgument(self::ARGUMENT_SIRET, InputArgument::OPTIONAL, 'SIRET of the auto-entrepreneur (14 digits)');
        $command->addOption(self::OPTION_ACCOUNT_ID, null, InputOption::VALUE_REQUIRED, 'Id of the tracked account (instead of the SIRET)');
    }

    /**
     * Exactly one of the SIRET and --account-id.
     *
     * @throws InvalidOptionException when none or both are given
     * @throws InvalidValue           for an invalid value
     */
    public static function accountSelector(InputInterface $input): AccountSelector
    {
        $siret = CommandOptions::argument($input, self::ARGUMENT_SIRET);
        $accountId = CommandOptions::text($input, self::OPTION_ACCOUNT_ID);

        return match (true) {
            $siret !== null && $accountId === null => AccountSelector::bySiret(Siret::fromString($siret)),
            $accountId !== null && $siret === null => AccountSelector::byAccountId(AccountId::fromString($accountId)),
            default => throw new InvalidOptionException('Give one of the SIRET argument or --account-id.'),
        };
    }

    /**
     * [siret] with --period=<YYYY-MM|YYYY-Tn>, or --record-id=<id>
     */
    public static function addDeclarationSelector(Command $command): void
    {
        $command->addArgument(self::ARGUMENT_SIRET, InputArgument::OPTIONAL, 'SIRET of the auto-entrepreneur (with --period)');
        $command->addOption(self::OPTION_PERIOD, null, InputOption::VALUE_REQUIRED, 'Period of the declaration: YYYY-MM or YYYY-Tn (with the SIRET)');
        $command->addOption(self::OPTION_RECORD_ID, null, InputOption::VALUE_REQUIRED, 'Id of the declaration record (instead of the SIRET and the period)');
    }

    /**
     * The SIRET with --period (the current record of the period), or --record-id.
     *
     * @throws InvalidOptionException for any other combination
     * @throws InvalidValue           for an invalid value
     * @throws ValidationFailed       for a period out of range
     */
    public static function declarationSelector(InputInterface $input): DeclarationSelector
    {
        $siret = CommandOptions::argument($input, self::ARGUMENT_SIRET);
        $period = CommandOptions::text($input, self::OPTION_PERIOD);
        $recordId = CommandOptions::text($input, self::OPTION_RECORD_ID);

        return match (true) {
            $siret !== null && $period !== null && $recordId === null => DeclarationSelector::current(Siret::fromString($siret), CommandPeriods::fromOption($period)),
            $recordId !== null && $siret === null && $period === null => DeclarationSelector::byRecordId(DeclarationRecordId::fromString($recordId)),
            default => throw new InvalidOptionException('Give the SIRET argument with --period, or --record-id.'),
        };
    }

    /**
     * [payment-reference] | --record-id=<id>
     */
    public static function addPaymentSelector(Command $command): void
    {
        $command->addArgument(self::ARGUMENT_PAYMENT_REFERENCE, InputArgument::OPTIONAL, 'Payment reference (your idempotency key)');
        $command->addOption(self::OPTION_RECORD_ID, null, InputOption::VALUE_REQUIRED, 'Id of the payment record (instead of the reference)');
    }

    /**
     * Exactly one of the payment reference and --record-id.
     *
     * @throws InvalidOptionException when none or both are given
     * @throws InvalidValue           for an invalid value
     */
    public static function paymentSelector(InputInterface $input): PaymentSelector
    {
        $reference = CommandOptions::argument($input, self::ARGUMENT_PAYMENT_REFERENCE);
        $recordId = CommandOptions::text($input, self::OPTION_RECORD_ID);

        return match (true) {
            $reference !== null && $recordId === null => PaymentSelector::byReference(PaymentReference::fromString($reference)),
            $recordId !== null && $reference === null => PaymentSelector::byRecordId(PaymentRecordId::fromString($recordId)),
            default => throw new InvalidOptionException('Give one of the payment reference argument or --record-id.'),
        };
    }
}
