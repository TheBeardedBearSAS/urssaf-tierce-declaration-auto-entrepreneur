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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;

/**
 * Records a mandate obtained outside the package, without any URSSAF call: after an accepted 030 whose recording
 * failed, or a mandate given on the auto-entrepreneur space. The birth date, when the account does not hold it, is asked
 * in a hidden prompt: an empty answer means none (031 will then need it). Idempotent, under the lock of the SIRET.
 */
#[AsCommand(name: 'urssaf:tdae:mandate:import', description: 'Record a mandate obtained outside the package (no URSSAF call)')]
final class ImportMandateCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly ImportMandateHandler $handler,
        private readonly GetAccountHandler $accounts,
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

            return $this->locks->forSiret($output, $tenant, $siret, OperationLocks::MANDATE, function () use ($input, $output, $tenant, $siret): int {
                $birthDate = CommandPersonalInputs::isStored($this->accounts, $tenant, $siret) ? null : CommandPersonalInputs::optionalBirthDate($input, $output, new QuestionHelper());
                CommandReports::renderFields($output, CommandViews::mandate(($this->handler)(new ImportMandate($tenant, $siret, $birthDate))));
                $output->writeln('Recorded without any call: nothing was sent to URSSAF.');

                return Command::SUCCESS;
            });
        });
    }
}
