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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\SepaRevocationFactory;

/**
 * Revokes a SEPA mandate of an auto-entrepreneur at URSSAF (method 071, ADR 0009). The effective date, when given with
 * --effective-on, must be after today in the business time zone (--skip-effective-date-check opts out of that local
 * check, ADR 0011). No lock.
 */
#[AsCommand(name: 'urssaf:tdae:sepa:revoke', description: 'Revoke a SEPA mandate of an auto-entrepreneur at URSSAF (method 071)')]
final class RevokeSepaMandateCommand extends Command
{
    private const ARGUMENT_RUM = 'rum';
    private const OPTION_EFFECTIVE_ON = 'effective-on';
    private const OPTION_SKIP_EFFECTIVE_DATE_CHECK = 'skip-effective-date-check';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly RevokeSepaMandateHandler $handler,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addSiret($this);
        $this->addArgument(self::ARGUMENT_RUM, InputArgument::REQUIRED, 'RUM of the SEPA mandate');
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        $this->addOption(self::OPTION_EFFECTIVE_ON, null, InputOption::VALUE_REQUIRED, 'Effective date of the revocation (YYYY-MM-DD, after today)');
        $this->addOption(self::OPTION_SKIP_EFFECTIVE_DATE_CHECK, null, InputOption::VALUE_NONE, 'Do not check the effective date locally');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $fields = [
                'siret' => CommandOptions::argument($input, CommandSelectors::ARGUMENT_SIRET) ?? '',
                'rum' => CommandOptions::argument($input, self::ARGUMENT_RUM) ?? '',
            ];
            $effectiveOn = CommandOptions::text($input, self::OPTION_EFFECTIVE_ON);
            if ($effectiveOn !== null) {
                $fields['effective_date'] = $effectiveOn;
            }
            $revocation = (new SepaRevocationFactory())->fromInput($fields);

            ($this->handler)(new RevokeSepaMandate($tenant, $revocation, CommandOptions::flag($input, self::OPTION_SKIP_EFFECTIVE_DATE_CHECK)));
            $output->writeln('SEPA mandate revoked.');

            return Command::SUCCESS;
        });
    }
}
