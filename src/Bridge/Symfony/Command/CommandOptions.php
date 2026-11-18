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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The options shared by the console commands (--tenant, --limit, --dry-run) and their reading. The tenants have their
 * own class ({@see CommandTenants}).
 */
final class CommandOptions
{
    private const POSITIVE = '/^[1-9][0-9]{0,8}$/D';

    public static function addTenant(Command $command, string $description): void
    {
        $command->addOption('tenant', null, InputOption::VALUE_REQUIRED, $description);
    }

    public static function addLimit(Command $command): void
    {
        $command->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of records to process (capped by the workflow policy)');
    }

    public static function addDryRun(Command $command): void
    {
        $command->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be done without calling URSSAF or changing anything');
    }

    public static function dryRun(InputInterface $input): bool
    {
        return self::flag($input, 'dry-run');
    }

    /**
     * Whether a VALUE_NONE option is given.
     */
    public static function flag(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }

    /**
     * @throws InvalidValue when --tenant is not a valid tenant identifier
     */
    public static function tenant(InputInterface $input): ?TenantId
    {
        $tenant = self::text($input, 'tenant');

        return $tenant === null ? null : TenantId::fromString($tenant);
    }

    /**
     * @throws InvalidOptionException for a value that is not a positive integer
     */
    public static function limit(InputInterface $input): ?int
    {
        $limit = self::text($input, 'limit');
        if ($limit === null) {
            return null;
        }

        return preg_match(self::POSITIVE, $limit) === 1 ? (int) $limit : throw new InvalidOptionException('The --limit option must be a positive integer.');
    }

    /**
     * The value of an option, null when it is not given.
     */
    public static function text(InputInterface $input, string $name): ?string
    {
        // @mago-expect analysis:mixed-assignment Symfony options are untyped: the value is narrowed on the next line.
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }

    /**
     * The value of an argument, null when it is not given.
     */
    public static function argument(InputInterface $input, string $name): ?string
    {
        // @mago-expect analysis:mixed-assignment Symfony arguments are untyped: the value is narrowed on the next line.
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : null;
    }
}
