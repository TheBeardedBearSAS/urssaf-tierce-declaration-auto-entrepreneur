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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;

/**
 * Notifies the mandate of an auto-entrepreneur to URSSAF (method 030). The birth date comes from the tracked account,
 * else from a hidden prompt (ADR 0010). Runs under the lock of the SIRET (ADR 0015 item 10). When it ends with
 * "mandate.recording_failed", URSSAF accepted the notification: never run it again, use urssaf:tdae:mandate:import.
 */
#[AsCommand(name: 'urssaf:tdae:mandate:notify', description: 'Notify the mandate of an auto-entrepreneur to URSSAF (method 030)')]
final class NotifyMandateCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly NotifyMandateHandler $handler,
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
                $birthDate = CommandPersonalInputs::isStored($this->accounts, $tenant, $siret) ? null : CommandPersonalInputs::birthDate($input, $output, new QuestionHelper());
                CommandReports::renderFields($output, CommandViews::mandate(($this->handler)(new NotifyMandate($tenant, $siret, $birthDate))));

                return Command::SUCCESS;
            });
        });
    }
}
