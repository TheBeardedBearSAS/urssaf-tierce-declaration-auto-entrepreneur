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

use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The personal inputs of the commands (ADR 0010): the birth date of the auto-entrepreneur and the IBAN of a payment
 * come from a hidden prompt, never from the arguments (shell history, process list, cron logs). An invalid value is an
 * invalid input (exit code 2) whose message never repeats the value.
 */
final class CommandPersonalInputs
{
    private const LABEL_BIRTH_DATE = 'Birth date (YYYY-MM-DD)';
    private const LABEL_IBAN = 'IBAN';

    /**
     * The birth date, read from a hidden prompt.
     *
     * @throws InvalidOptionException in non-interactive mode, or without an answer
     * @throws InvalidValue           for an invalid date
     */
    public static function birthDate(InputInterface $input, OutputInterface $output, QuestionHelper $helper): CalendarDate
    {
        return CalendarDate::fromString(CommandPrompts::hiddenValue($input, $output, $helper, self::LABEL_BIRTH_DATE));
    }

    /**
     * The birth date, read from a hidden prompt: an empty answer means none.
     *
     * @throws InvalidOptionException in non-interactive mode, or without an answer
     * @throws InvalidValue           for an invalid date
     */
    public static function optionalBirthDate(InputInterface $input, OutputInterface $output, QuestionHelper $helper): ?CalendarDate
    {
        $answer = CommandPrompts::hiddenValue($input, $output, $helper, self::LABEL_BIRTH_DATE);

        return $answer === '' ? null : CalendarDate::fromString($answer);
    }

    /**
     * The IBAN of a payment, read from a hidden prompt (an invalid value is refused by the payment order factory). An
     * empty answer is refused: the operator asked for an IBAN, which the order would otherwise silently leave out.
     *
     * @throws InvalidOptionException in non-interactive mode, or without an answer
     */
    public static function iban(InputInterface $input, OutputInterface $output, QuestionHelper $helper): string
    {
        $iban = CommandPrompts::hiddenValue($input, $output, $helper, self::LABEL_IBAN);

        return $iban === '' ? throw new InvalidOptionException(self::LABEL_IBAN . ' was not given.') : $iban;
    }

    /**
     * Whether the tracked account of the SIRET already holds a birth date: the use case reads it, nothing is asked.
     */
    public static function isStored(GetAccountHandler $accounts, TenantId $tenant, Siret $siret): bool
    {
        try {
            return $accounts(new GetAccount($tenant, AccountSelector::bySiret($siret)))->hasBirthDate;
        } catch (RecordNotFound) {
            return false;
        }
    }
}
