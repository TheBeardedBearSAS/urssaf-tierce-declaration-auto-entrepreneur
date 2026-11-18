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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandOptions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * @mago-expect lint:too-many-methods One test per option and per reading rule.
 */
final class CommandOptionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private static function input(array $values): ArrayInput
    {
        $command = new Command('probe');
        CommandOptions::addTenant($command, 'Tenant');
        CommandOptions::addLimit($command);
        CommandOptions::addDryRun($command);
        $command->addOption('count', null, InputOption::VALUE_REQUIRED);
        $command->addOption('force', null, InputOption::VALUE_NONE);
        $command->addArgument('name', InputArgument::OPTIONAL);

        return new ArrayInput($values, $command->getDefinition());
    }

    public function testTheOptionsAreDeclared(): void
    {
        $command = new Command('probe');
        CommandOptions::addTenant($command, 'Tenant of the run');
        CommandOptions::addLimit($command);
        CommandOptions::addDryRun($command);

        static::assertSame('Tenant of the run', $command->getDefinition()->getOption('tenant')->getDescription());
        static::assertTrue($command->getDefinition()->getOption('tenant')->isValueRequired());
        static::assertTrue($command->getDefinition()->getOption('limit')->isValueRequired());
        static::assertSame('Maximum number of records to process (capped by the workflow policy)', $command->getDefinition()->getOption('limit')->getDescription());
        static::assertFalse($command->getDefinition()->getOption('dry-run')->acceptValue());
        static::assertSame('List what would be done without calling URSSAF or changing anything', $command->getDefinition()->getOption('dry-run')->getDescription());
    }

    public function testTheTenantIsReadWhenGiven(): void
    {
        static::assertNull(CommandOptions::tenant(self::input([])));
        static::assertSame('tenant-1', CommandOptions::tenant(self::input(['--tenant' => 'tenant-1']))?->value());
    }

    public function testAnInvalidTenantIsRefusedByTheDomain(): void
    {
        $this->expectException(InvalidValue::class);

        CommandOptions::tenant(self::input(['--tenant' => ' ']));
    }

    public function testTheDryRunIsOnlyOnWhenGiven(): void
    {
        static::assertFalse(CommandOptions::dryRun(self::input([])));
        static::assertTrue(CommandOptions::dryRun(self::input(['--dry-run' => true])));
    }

    public function testAFlagIsOnlyOnWhenGiven(): void
    {
        static::assertFalse(CommandOptions::flag(self::input([]), 'force'));
        static::assertTrue(CommandOptions::flag(self::input(['--force' => true]), 'force'));
        static::assertFalse(CommandOptions::flag(self::input(['--count' => 'true']), 'count'));
    }

    public function testTextAndArgumentAreNullWhenNotGiven(): void
    {
        static::assertNull(CommandOptions::text(self::input([]), 'count'));
        static::assertSame('5', CommandOptions::text(self::input(['--count' => '5']), 'count'));
        static::assertNull(CommandOptions::text(self::input(['--force' => true]), 'force'));
        static::assertNull(CommandOptions::argument(self::input([]), 'name'));
        static::assertSame('x', CommandOptions::argument(self::input(['name' => 'x']), 'name'));
    }

    #[DataProvider('limits')]
    public function testALimitIsAPositiveInteger(?string $value, ?int $expected): void
    {
        static::assertSame($expected, CommandOptions::limit(self::input($value === null ? [] : ['--limit' => $value])));
    }

    /**
     * @return iterable<string, array{?string, ?int}>
     */
    public static function limits(): iterable
    {
        yield 'absent' => [null, null];
        yield 'one' => ['1', 1];
        yield 'many digits' => ['123456789', 123_456_789];
    }

    #[DataProvider('invalidLimits')]
    public function testAnInvalidLimitIsRefused(string $value): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The --limit option must be a positive integer.');

        CommandOptions::limit(self::input(['--limit' => $value]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['ten'];
        yield 'decimal' => ['1.5'];
        yield 'leading zero' => ['01'];
        yield 'too long' => ['1234567890'];
        yield 'trailing newline' => ["1\n"];
        yield 'empty' => [''];
        yield 'prefixed' => ['x1'];
    }
}
