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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The console commands of the bundle (`urssaf:tdae:*`, ADR 0015 item 8): which ones exist, their arguments and options.
 * The exact set of the bundle (with the `check` command) is asserted by the surface test.
 */
final class CommandsRegistrationTest extends TestCase
{
    /**
     * Every command: its required arguments, its optional arguments, its options that take a value and its flags (the
     * tenant of a command is optional: the only configured tenant by default).
     *
     * @return array<string, array{list<string>, list<string>, list<string>, list<string>}>
     */
    private static function commands(): array
    {
        return [
            'urssaf:tdae:account:sync' => [[], ['siret'], ['limit', 'tenant'], ['async', 'dry-run', 'with-birth-date']],
            'urssaf:tdae:account:forget' => [[], ['siret'], ['account-id', 'tenant'], ['allow-active-mandate', 'allow-unknown-outcomes', 'force']],
            'urssaf:tdae:mandate:notify' => [['siret'], [], ['tenant'], []],
            'urssaf:tdae:mandate:cancel' => [['siret'], [], ['tenant'], ['force']],
            'urssaf:tdae:mandate:import' => [['siret'], [], ['tenant'], []],
            'urssaf:tdae:mandate:record-cancellation' => [['siret'], [], ['tenant'], []],
            'urssaf:tdae:estimate' => [['siret'], [], ['payload', 'tenant'], ['skip-mandate-check']],
            'urssaf:tdae:declare' => [['siret'], [], ['payload', 'tenant'], ['skip-mandate-check', 'skip-periodicity-check']],
            'urssaf:tdae:pay' => [['siret', 'payment-reference'], [], ['payload', 'rum', 'tenant'], ['allow-additional-payment', 'skip-mandate-check', 'with-iban']],
            'urssaf:tdae:sepa:register' => [['siret'], [], ['payload', 'tenant'], []],
            'urssaf:tdae:sepa:revoke' => [['siret', 'rum'], [], ['effective-on', 'tenant'], ['skip-effective-date-check']],
            'urssaf:tdae:sepa:list' => [['siret'], [], ['tenant'], []],
            'urssaf:tdae:declarations:reconcile' => [[], [], ['limit'], ['dry-run']],
            'urssaf:tdae:payments:reconcile' => [[], [], ['limit'], ['dry-run']],
            'urssaf:tdae:declarations:resolve' => [[], ['siret'], ['declared-as', 'period', 'record-id', 'tenant'], ['not-received']],
            'urssaf:tdae:payments:resolve' => [[], ['payment-reference'], ['paid-as', 'record-id', 'tenant'], ['not-received']],
            'urssaf:tdae:requeue' => [[], [], ['limit', 'tenant'], []],
        ];
    }

    private const OUTBOX_COMMANDS = ['urssaf:tdae:outbox:relay', 'urssaf:tdae:outbox:purge'];

    /**
     * @return list<string>
     */
    private static function names(Harness $harness): array
    {
        $names = [];
        foreach ((new Application($harness->kernel))->all('urssaf:tdae') as $key => $command) {
            $name = $command->getName();
            if ($name !== null && $key === $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function testEveryCommandIsRegisteredUnderTheNamespaceOfThePackage(): void
    {
        $names = self::names(Harness::boot());

        static::assertSame([], array_diff(array_keys(self::commands()), $names));
        static::assertSame([], array_intersect(self::OUTBOX_COMMANDS, $names));
    }

    public function testTheOutboxCommandsComeWithTheOutbox(): void
    {
        $names = self::names(Harness::boot(['outbox' => ['enabled' => true]]));

        static::assertSame([], array_diff([...array_keys(self::commands()), ...self::OUTBOX_COMMANDS], $names));
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @param list<string> $valued
     * @param list<string> $flags
     */
    #[DataProvider('definitions')]
    public function testTheArgumentsAndTheOptionsOfACommand(string $name, array $required, array $optional, array $valued, array $flags): void
    {
        $definition = (new Application(Harness::boot()->kernel))->find($name)->getDefinition();

        static::assertSame([...$required, ...$optional], array_keys($definition->getArguments()));
        static::assertSame($required, array_keys(array_filter($definition->getArguments(), static fn(InputArgument $argument): bool => $argument->isRequired())));
        static::assertSame($valued, self::sortedKeys(array_filter($definition->getOptions(), static fn(InputOption $option): bool => $option->isValueRequired())));
        static::assertSame($flags, self::sortedKeys(array_filter($definition->getOptions(), static fn(InputOption $option): bool => !$option->acceptValue())));
    }

    /**
     * @param array<array-key, mixed> $map
     *
     * @return list<string>
     */
    private static function sortedKeys(array $map): array
    {
        $keys = array_map(strval(...), array_keys($map));
        sort($keys);

        return $keys;
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, list<string>, list<string>}>
     */
    public static function definitions(): iterable
    {
        foreach (self::commands() as $name => [$required, $optional, $valued, $flags]) {
            yield $name => [$name, $required, $optional, $valued, $flags];
        }
    }

    public function testTheOutboxCommandsDefinitions(): void
    {
        $application = new Application(Harness::boot(['outbox' => ['enabled' => true]])->kernel);

        static::assertSame(['limit'], array_keys($application->find('urssaf:tdae:outbox:relay')->getDefinition()->getOptions()));
        static::assertSame(['older-than'], array_keys($application->find('urssaf:tdae:outbox:purge')->getDefinition()->getOptions()));
        static::assertTrue($application->find('urssaf:tdae:outbox:purge')->getDefinition()->getOption('older-than')->isValueRequired());
    }

    public function testEveryCommandHasADescription(): void
    {
        $application = new Application(Harness::boot(['outbox' => ['enabled' => true]])->kernel);

        foreach ([...array_keys(self::commands()), ...self::OUTBOX_COMMANDS] as $name) {
            static::assertNotSame('', $application->find($name)->getDescription(), $name);
        }
    }

    public function testNoCommandAcceptsAPersonalValueAsAnOption(): void
    {
        $application = new Application(Harness::boot(['outbox' => ['enabled' => true]])->kernel);

        foreach ($application->all('urssaf:tdae') as $command) {
            foreach (array_keys($command->getDefinition()->getOptions()) as $option) {
                static::assertNotContains($option, ['birth-date', 'iban', 'bic', 'holder', 'address', 'amount', 'sales', 'turnover'], \sprintf('%s --%s', $command->getName() ?? '', $option));
            }
        }
    }
}
