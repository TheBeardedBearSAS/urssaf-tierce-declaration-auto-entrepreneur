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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\NewSepaMandateFactory;

/**
 * Registers the SEPA mandate of an auto-entrepreneur at URSSAF (method 070, ADR 0009). The mandate (BIC, IBAN, holder,
 * address) comes from a JSON file (--payload, see Domain\Sepa\Input\NewSepaMandateFactory): it is passed to URSSAF and
 * never stored. The output holds the RUM and the masked IBAN only. No lock: URSSAF refuses a second mandate.
 */
#[AsCommand(name: 'urssaf:tdae:sepa:register', description: 'Register the SEPA mandate of an auto-entrepreneur at URSSAF (method 070)')]
final class RegisterSepaMandateCommand extends Command
{
    private const OPTION_PAYLOAD = 'payload';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly RegisterSepaMandateHandler $handler,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addSiret($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        CommandPayloads::add($this, self::OPTION_PAYLOAD, 'JSON file with the SEPA mandate (required): see Domain\Sepa\Input\NewSepaMandateFactory');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $siret = CommandSelectors::siret($input);
            $mandate = (new NewSepaMandateFactory())->fromInput(CommandPayloads::require($input, self::OPTION_PAYLOAD));

            [$rum, $iban] = CommandViews::sepaMandate(($this->handler)(new RegisterSepaMandate($tenant, $siret, $mandate)));
            CommandReports::renderFields($output, [['RUM', $rum], ['IBAN', $iban]]);

            return Command::SUCCESS;
        });
    }
}
