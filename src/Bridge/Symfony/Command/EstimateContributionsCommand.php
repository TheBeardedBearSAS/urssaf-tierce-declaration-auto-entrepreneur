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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\EstimateContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\EstimateContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverFactory;

/**
 * Estimates the contributions of a turnover (method 040, a read that changes nothing). The turnover comes from a JSON
 * file (--payload, see Domain\Contribution\Input\TurnoverFactory).
 */
#[AsCommand(name: 'urssaf:tdae:estimate', description: 'Estimate the contributions of a turnover (method 040)')]
final class EstimateContributionsCommand extends Command
{
    private const OPTION_PAYLOAD = 'payload';
    private const OPTION_SKIP_MANDATE_CHECK = 'skip-mandate-check';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly EstimateContributionsHandler $handler,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addSiret($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        CommandPayloads::add($this, self::OPTION_PAYLOAD, 'JSON file with the turnover (required): see Domain\Contribution\Input\TurnoverFactory');
        $this->addOption(self::OPTION_SKIP_MANDATE_CHECK, null, InputOption::VALUE_NONE, 'Do not require an active local mandate (when "workflow.require_local_mandate" is on)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $siret = CommandSelectors::siret($input);
            $turnover = (new TurnoverFactory())->fromInput(CommandPayloads::require($input, self::OPTION_PAYLOAD));

            $estimate = ($this->handler)(new EstimateContributions($tenant, $siret, $turnover, CommandOptions::flag($input, self::OPTION_SKIP_MANDATE_CHECK)));
            CommandReports::renderFields($output, CommandViews::estimate($estimate));

            return Command::SUCCESS;
        });
    }
}
