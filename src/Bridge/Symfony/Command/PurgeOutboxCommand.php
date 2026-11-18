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

use DateInterval;
use Exception;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;

/**
 * Deletes the outbox entries dispatched for longer than the retention: "outbox.retention" (held by the workflow policy),
 * or --older-than for this run. Under one global lock. Safety cron: daily (registered only with "outbox.enabled").
 */
#[AsCommand(name: 'urssaf:tdae:outbox:purge', description: 'Delete the outbox entries dispatched for longer than the retention')]
final class PurgeOutboxCommand extends Command
{
    private const OPTION_OLDER_THAN = 'older-than';

    public function __construct(private readonly PurgeOutboxHandler $purge, private readonly CommandLocks $locks)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(self::OPTION_OLDER_THAN, null, InputOption::VALUE_REQUIRED, 'Retention of this run, an ISO 8601 duration such as P30D (default: "outbox.retention")');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $retention = self::retention(CommandOptions::text($input, self::OPTION_OLDER_THAN));

            return $this->locks->globally($output, OperationLocks::OUTBOX_PURGE, function () use ($output, $retention): int {
                $output->writeln(\sprintf('%d dispatched outbox entries deleted.', ($this->purge)(new PurgeOutbox($retention))));

                return Command::SUCCESS;
            });
        });
    }

    /**
     * @throws InvalidOptionException for a value that is not an ISO 8601 duration
     */
    private static function retention(?string $value): ?DateInterval
    {
        if ($value === null) {
            return null;
        }
        try {
            return new DateInterval($value);
        } catch (Exception $failure) {
            throw new InvalidOptionException('The --older-than option must be an ISO 8601 duration such as P30D.', 0, $failure);
        }
    }
}
