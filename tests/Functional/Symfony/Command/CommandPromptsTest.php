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
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandPrompts;

final class CommandPromptsTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private static function input(string $stream, array $values = []): ArrayInput
    {
        $command = new Command('probe');
        CommandPrompts::addForce($command);
        $input = new ArrayInput($values, $command->getDefinition());
        $handle = fopen('php://memory', 'r+');
        \assert($handle !== false, 'The memory stream is available.');
        fwrite($handle, $stream);
        rewind($handle);
        $input->setStream($handle);

        return $input;
    }

    public function testTheForceOptionIsDeclared(): void
    {
        $command = new Command('probe');
        CommandPrompts::addForce($command);

        static::assertFalse($command->getDefinition()->getOption('force')->acceptValue());
        static::assertSame('Do not ask for a confirmation (required when not interactive)', $command->getDefinition()->getOption('force')->getDescription());
    }

    public function testTheAnswerIsReadFromTheInputWithoutBeingEchoed(): void
    {
        $output = new BufferedOutput();

        static::assertSame('secret-value', CommandPrompts::hiddenValue(self::input("secret-value\n"), $output, new QuestionHelper(), 'IBAN'));

        static::assertSame("IBAN: \n", $output->fetch());
    }

    public function testAnEmptyAnswerIsReturnedAsIs(): void
    {
        static::assertSame('', CommandPrompts::hiddenValue(self::input("\n"), new BufferedOutput(), new QuestionHelper(), 'IBAN'));
    }

    public function testANonInteractiveRunIsRefused(): void
    {
        $input = self::input("secret-value\n");
        $input->setInteractive(false);
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('IBAN is read from a hidden prompt: run the command interactively.');

        CommandPrompts::hiddenValue($input, new BufferedOutput(), new QuestionHelper(), 'IBAN');
    }

    public function testAMissingAnswerIsRefused(): void
    {
        try {
            CommandPrompts::hiddenValue(self::input(''), new BufferedOutput(), new QuestionHelper(), 'IBAN');
            static::fail('A missing answer must be refused.');
        } catch (InvalidOptionException $failure) {
            static::assertSame('IBAN was not given.', $failure->getMessage());
            static::assertSame(0, $failure->getCode());
            static::assertInstanceOf(MissingInputException::class, $failure->getPrevious());
        }
    }

    public function testForceConfirmsWithoutAskingAnything(): void
    {
        $output = new BufferedOutput();
        $input = self::input('', ['--force' => true]);
        $input->setInteractive(false);

        static::assertTrue(CommandPrompts::confirmed($input, $output, new QuestionHelper(), 'Sure?', 'Use --force.'));
        static::assertSame('', $output->fetch());
    }

    public function testANonInteractiveRunNeedsForce(): void
    {
        $input = self::input("yes\n");
        $input->setInteractive(false);
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Use --force.');

        CommandPrompts::confirmed($input, new BufferedOutput(), new QuestionHelper(), 'Sure?', 'Use --force.');
    }

    public function testAYesAnswerConfirms(): void
    {
        $output = new BufferedOutput();

        static::assertTrue(CommandPrompts::confirmed(self::input("yes\n"), $output, new QuestionHelper(), 'Sure?', 'Use --force.'));
        static::assertStringContainsString('Sure? [y/N] ', $output->fetch());
    }

    public function testANoAnswerAndAnEmptyAnswerDoNotConfirm(): void
    {
        static::assertFalse(CommandPrompts::confirmed(self::input("no\n"), new BufferedOutput(), new QuestionHelper(), 'Sure?', 'Use --force.'));
        static::assertFalse(CommandPrompts::confirmed(self::input("\n"), new BufferedOutput(), new QuestionHelper(), 'Sure?', 'Use --force.'));
    }
}
