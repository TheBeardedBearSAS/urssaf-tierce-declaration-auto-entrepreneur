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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ForgetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ForgetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Erases an auto-entrepreneur, its mandates, its declarations and its payments from the local store (GDPR, ADR 0017),
 * after a confirmation (or --force). Nothing is sent to URSSAF. The output holds identifiers and counts only; the
 * confirmation never shows the SIRET. "--force" only lifts the confirmation: the two opt-ins override the workflow
 * policy for this run. Runs under the lock of the SIRET.
 */
#[AsCommand(name: 'urssaf:tdae:account:forget', description: 'Erase an auto-entrepreneur with its mandates, declarations and payments from the local store (GDPR; nothing is sent to URSSAF)')]
final class ForgetAccountCommand extends Command
{
    private const OPTION_ALLOW_ACTIVE_MANDATE = 'allow-active-mandate';
    private const OPTION_ALLOW_UNKNOWN_OUTCOMES = 'allow-unknown-outcomes';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly ForgetAccountHandler $handler,
        private readonly CommandLocks $locks,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addAccountSelector($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        $this->addOption(self::OPTION_ALLOW_ACTIVE_MANDATE, null, InputOption::VALUE_NONE, 'Erase the account even if its mandate is still active (it stays active at URSSAF: cancel it first with urssaf:tdae:mandate:cancel)');
        $this->addOption(self::OPTION_ALLOW_UNKNOWN_OUTCOMES, null, InputOption::VALUE_NONE, 'Erase the declarations and payments whose outcome is unknown without resolving them');
        CommandPrompts::addForce($this);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $selector = CommandSelectors::accountSelector($input);
            $allowUnknownOutcomes = CommandOptions::flag($input, self::OPTION_ALLOW_UNKNOWN_OUTCOMES) ? true : null;
            $question = 'Erase this auto-entrepreneur, its mandates, declarations and payments from the local store? This cannot be undone and nothing is sent to URSSAF.'
                . ($allowUnknownOutcomes === true ? ' The records whose outcome is unknown are erased without being resolved.' : '');
            if (!CommandPrompts::confirmed($input, $output, new QuestionHelper(), $question, 'Erasing an auto-entrepreneur needs a confirmation: use --force when not interactive.')) {
                $output->writeln('Aborted: nothing was erased.');

                return Command::SUCCESS;
            }

            return $selector->reference instanceof Siret
                ? $this->locks->forSiret($output, $tenant, $selector->reference, OperationLocks::MANDATE, fn(): int => $this->erase($input, $output, $tenant, $selector, $allowUnknownOutcomes))
                : $this->locks->forAccount($output, $tenant, $selector->reference, OperationLocks::MANDATE, fn(): int => $this->erase($input, $output, $tenant, $selector, $allowUnknownOutcomes));
        });
    }

    private function erase(InputInterface $input, OutputInterface $output, TenantId $tenant, AccountSelector $selector, ?bool $allowUnknownOutcomes): int
    {
        $erasure = ($this->handler)(new ForgetAccount(
            $tenant,
            $selector,
            CommandOptions::flag($input, self::OPTION_ALLOW_ACTIVE_MANDATE) ? true : null,
            $allowUnknownOutcomes,
        ));
        CommandReports::renderFields($output, CommandViews::erasure($erasure));
        $output->writeln('Erased from the local store only: nothing was sent to URSSAF.');

        return Command::SUCCESS;
    }
}
