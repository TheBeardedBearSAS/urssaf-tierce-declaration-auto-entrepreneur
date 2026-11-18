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
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Prompts of the commands: the personal values (birth date, IBAN) are never options, so that they reach neither the
 * shell history, nor the process list, nor a cron log (ADR 0010). The confirmations never show a SIRET.
 */
final class CommandPrompts
{
    private const OPTION_FORCE = 'force';

    /**
     * The --force option, which only lifts the confirmation.
     */
    public static function addForce(Command $command): void
    {
        $command->addOption(self::OPTION_FORCE, null, InputOption::VALUE_NONE, 'Do not ask for a confirmation (required when not interactive)');
    }

    /**
     * Whether the operator confirmed: always with --force, else after a [y/N] question (no by default).
     *
     * @throws InvalidOptionException without --force when the run is not interactive
     */
    public static function confirmed(InputInterface $input, OutputInterface $output, QuestionHelper $helper, string $question, string $nonInteractive): bool
    {
        if (CommandOptions::flag($input, self::OPTION_FORCE)) {
            return true;
        }
        if (!$input->isInteractive()) {
            throw new InvalidOptionException($nonInteractive);
        }

        return $helper->ask($input, $output, new ConfirmationQuestion($question . ' [y/N] ', false)) === true;
    }

    /**
     * A personal value read from a hidden prompt (never from argv, never echoed); '' for an empty answer.
     *
     * @throws InvalidOptionException in non-interactive mode, or when no answer is given
     */
    public static function hiddenValue(InputInterface $input, OutputInterface $output, QuestionHelper $helper, string $label): string
    {
        if (!$input->isInteractive()) {
            throw new InvalidOptionException(\sprintf('%s is read from a hidden prompt: run the command interactively.', $label));
        }
        $question = new Question($label . ': ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        try {
            // @mago-expect analysis:mixed-assignment The question helper is untyped: the answer is narrowed below (an empty answer is null).
            $answer = $helper->ask($input, $output, $question);
        } catch (MissingInputException $failure) {
            throw new InvalidOptionException(\sprintf('%s was not given.', $label), 0, $failure);
        }

        return \is_string($answer) ? $answer : '';
    }
}
