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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\ListSepaMandates;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\ListSepaMandatesHandler;

/**
 * Lists the SEPA mandates of an auto-entrepreneur (method 075, a read). Prints the RUM and the masked IBAN of each one,
 * never the BIC, the holder, the address nor the bank (ADR 0010).
 */
#[AsCommand(name: 'urssaf:tdae:sepa:list', description: 'List the SEPA mandates of an auto-entrepreneur (method 075)')]
final class ListSepaMandatesCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly ListSepaMandatesHandler $handler,
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

            $table = new Table($output);
            $table->setHeaders(['RUM', 'IBAN']);
            $table->setRows(array_map(CommandViews::sepaMandate(...), ($this->handler)(new ListSepaMandates($tenant, $siret))));
            $table->render();

            return Command::SUCCESS;
        });
    }
}
