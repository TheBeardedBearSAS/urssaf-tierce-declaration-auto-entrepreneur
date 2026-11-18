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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RecordMandateCancellation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RecordMandateCancellationHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;

/**
 * Records the cancellation of the latest mandate of an auto-entrepreneur without any URSSAF call: cancelled on the
 * auto-entrepreneur space, or recovery after "mandate.recording_failed" of a 031. Idempotent, under the lock of the SIRET.
 */
#[AsCommand(name: 'urssaf:tdae:mandate:record-cancellation', description: 'Record the cancellation of a mandate obtained outside the package (no URSSAF call)')]
final class RecordMandateCancellationCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly RecordMandateCancellationHandler $handler,
        private readonly CommandLocks $locks,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addSiret($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $siret = CommandSelectors::siret($input);

            return $this->locks->forSiret($output, $tenant, $siret, OperationLocks::MANDATE, function () use ($output, $tenant, $siret): int {
                CommandReports::renderFields($output, CommandViews::mandate(($this->handler)(new RecordMandateCancellation($tenant, $siret))));
                $output->writeln('Recorded without any call: nothing was sent to URSSAF.');

                return Command::SUCCESS;
            });
        });
    }
}
