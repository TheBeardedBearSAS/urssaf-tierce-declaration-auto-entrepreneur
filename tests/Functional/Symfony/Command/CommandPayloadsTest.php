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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandPayloads;

/**
 * @mago-expect lint:too-many-methods One test per reading rule and per limit.
 */
final class CommandPayloadsTest extends TestCase
{
    private static function input(?string $path): ArrayInput
    {
        $command = new Command('probe');
        CommandPayloads::add($command, 'payload', 'A payload');

        return new ArrayInput($path === null ? [] : ['--payload' => $path], $command->getDefinition());
    }

    /**
     * A file of var/ (never /tmp) holding the content, removed by the callback's caller.
     */
    private static function file(string $content): string
    {
        $directory = \dirname(__DIR__, 4) . '/var/t5';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        $path = $directory . '/payload-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $content);

        return $path;
    }

    public function testTheOptionIsDeclared(): void
    {
        $command = new Command('probe');
        CommandPayloads::add($command, 'payload', 'The payload file');

        static::assertTrue($command->getDefinition()->getOption('payload')->isValueRequired());
        static::assertSame('The payload file', $command->getDefinition()->getOption('payload')->getDescription());
    }

    public function testAPayloadIsNullWhenNotGiven(): void
    {
        static::assertNull(CommandPayloads::read(self::input(null), 'payload'));
    }

    public function testAJsonObjectIsRead(): void
    {
        static::assertSame(
            ['period' => ['year' => 2026, 'month' => 10], 'turnover' => ['sales' => 1000]],
            CommandPayloads::read(self::input(Fields::payload('declaration.json')), 'payload'),
        );
    }

    public function testAJsonListIsRead(): void
    {
        $path = self::file('[1, 2]');
        try {
            static::assertSame([1, 2], CommandPayloads::read(self::input($path), 'payload'));
        } finally {
            unlink($path);
        }
    }

    public function testARequiredPayloadIsReadOrRefused(): void
    {
        static::assertCount(2, CommandPayloads::require(self::input(Fields::payload('declaration.json')), 'payload'));

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The --payload option is required.');

        CommandPayloads::require(self::input(null), 'payload');
    }

    #[DataProvider('unusableFiles')]
    public function testAnUnusableFileIsRefusedWithoutItsContent(string $path, string $message): void
    {
        try {
            CommandPayloads::read(self::input($path), 'payload');
            static::fail('The file is unusable.');
        } catch (InvalidOptionException $failure) {
            static::assertSame($message, $failure->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableFiles(): iterable
    {
        yield 'missing file' => [Fields::payload('missing.json'), 'The --payload file cannot be read, or is larger than 1 MiB.'];
        yield 'a directory' => [__DIR__, 'The --payload file cannot be read, or is larger than 1 MiB.'];
        yield 'invalid JSON' => [Fields::payload('invalid.json'), 'The --payload file is not valid JSON.'];
        yield 'empty file' => [Fields::payload('empty.json'), 'The --payload file is not valid JSON.'];
        yield 'a JSON scalar' => [Fields::payload('scalar.json'), 'The --payload file must hold a JSON object or list.'];
    }

    public function testAFileOfExactlyOneMebibyteIsRead(): void
    {
        $path = self::file('["' . str_repeat('a', CommandPayloads::MAX_BYTES - 4) . '"]');
        try {
            static::assertSame(CommandPayloads::MAX_BYTES, filesize($path));
            static::assertCount(1, CommandPayloads::require(self::input($path), 'payload'));
        } finally {
            unlink($path);
        }
    }

    public function testAFileOneByteOverOneMebibyteIsRefused(): void
    {
        $path = self::file('["' . str_repeat('a', CommandPayloads::MAX_BYTES - 3) . '"]');
        try {
            $this->expectException(InvalidOptionException::class);
            $this->expectExceptionMessage('The --payload file cannot be read, or is larger than 1 MiB.');

            CommandPayloads::read(self::input($path), 'payload');
        } finally {
            unlink($path);
        }
    }

    public function testADocumentNestedUpToTheMaximumDepthIsReadAndDeeperRefused(): void
    {
        $depth = CommandPayloads::MAX_DEPTH;
        // The decoder counts the innermost array: depth - 1 nested arrays is the deepest document it accepts.
        $ok = self::file(str_repeat('[', $depth - 1) . str_repeat(']', $depth - 1));
        $deep = self::file(str_repeat('[', $depth) . str_repeat(']', $depth));
        try {
            static::assertIsArray(CommandPayloads::read(self::input($ok), 'payload'));

            $this->expectException(InvalidOptionException::class);
            $this->expectExceptionMessage('The --payload file is not valid JSON.');

            CommandPayloads::read(self::input($deep), 'payload');
        } finally {
            unlink($ok);
            unlink($deep);
        }
    }
}
