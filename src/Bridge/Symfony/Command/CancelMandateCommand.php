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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\CancelMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\CancelMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;

/**
 * Cancels the mandate of an auto-entrepreneur at URSSAF (method 031), after a confirmation (or --force). The birth date
 * comes from the tracked account, else from a hidden prompt (ADR 0010). Runs under the lock of the SIRET. When it ends
 * with "mandate.recording_failed", URSSAF accepted the cancellation: never run it again, use
 * urssaf:tdae:mandate:record-cancellation.
 */
#[AsCommand(name: 'urssaf:tdae:mandate:cancel', description: 'Cancel the mandate of an auto-entrepreneur at URSSAF (method 031)')]
final class CancelMandateCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly CancelMandateHandler $handler,
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
        CommandPrompts::addForce($this);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $siret = CommandSelectors::siret($input);
            $helper = new QuestionHelper();
            if (!CommandPrompts::confirmed($input, $output, $helper, 'Cancel the mandate of this auto-entrepreneur at URSSAF?', 'Cancelling a mandate needs a confirmation: use --force when not interactive.')) {
                $output->writeln('Aborted: nothing was sent to URSSAF.');

                return Command::SUCCESS;
            }

            return $this->locks->forSiret($output, $tenant, $siret, OperationLocks::MANDATE, function () use ($input, $output, $helper, $tenant, $siret): int {
                $birthDate = CommandPersonalInputs::isStored($this->accounts, $tenant, $siret) ? null : CommandPersonalInputs::birthDate($input, $output, $helper);
                CommandReports::renderFields($output, CommandViews::mandate(($this->handler)(new CancelMandate($tenant, $siret, $birthDate))));

                return Command::SUCCESS;
            });
        });
    }
}
