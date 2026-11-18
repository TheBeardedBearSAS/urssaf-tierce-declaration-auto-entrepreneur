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

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RequeueAfterInvalidCredentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RequeueAfterInvalidCredentialsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;

/**
 * Moves the declarations and the payments refused for invalid credentials back to pending, once the credentials of the
 * tenant are fixed. They are then sent again by the host: declare and pay carry their payload and their IBAN, the
 * record never does (ADR 0010). Under the lock of the tenant. Never scheduled.
 */
#[AsCommand(name: 'urssaf:tdae:requeue', description: 'Move the declarations and payments refused for invalid credentials back to pending (after fixing the credentials)')]
final class RequeueInvalidCredentialsCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly RequeueAfterInvalidCredentialsHandler $requeue,
        private readonly CommandLocks $locks,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        CommandOptions::addLimit($this);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $limit = CommandOptions::limit($input);

            return $this->locks->forTenant($output, $tenant, OperationLocks::REQUEUE, function () use ($output, $tenant, $limit): int {
                $report = ($this->requeue)(new RequeueAfterInvalidCredentials($tenant, $limit));
                $table = new Table($output);
                $table->setHeaders(['Records', 'Requeued']);
                $table->setRows([['declarations', $report->declarations], ['payments', $report->payments]]);
                $table->render();
                $output->writeln('The requeued records are pending: send them again with urssaf:tdae:declare and urssaf:tdae:pay.');

                return Command::SUCCESS;
            });
        });
    }
}
