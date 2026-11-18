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
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Tracks or refreshes auto-entrepreneurs from URSSAF (method 020, a read). With a SIRET: tracks it when it is not tracked
 * yet, else refreshes it (--with-birth-date also stores the birth date, asked in a hidden prompt), under the lock of the
 * SIRET. Without: refreshes the least recently synced accounts of --tenant, else of every tenant, one lock per tenant.
 * `--dry-run` lists what would be refreshed; `--async` dispatches one message per tenant instead (Messenger, ADR 0015
 * item 9). The only polling of the package: run it daily.
 *
 * @mago-expect lint:cyclomatic-complexity One command, four modes (one SIRET, one tenant, every tenant, dry run or async): the mode checks are the point.
 */
#[AsCommand(name: 'urssaf:tdae:account:sync', description: 'Track or refresh auto-entrepreneurs (method 020)')]
final class SyncAccountsCommand extends Command
{
    private const OPTION_WITH_BIRTH_DATE = 'with-birth-date';
    private const OPTION_ASYNC = 'async';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly AeAccountRepository $accounts,
        private readonly TrackAccountHandler $track,
        private readonly SyncAccountHandler $syncOne,
        private readonly SyncAccountsHandler $syncTenant,
        private readonly GetAccountHandler $getAccount,
        private readonly WorkflowPolicy $policy,
        private readonly OperationLocks $locks,
        private readonly CommandLocks $accountLocks,
        private readonly ?MessageBusInterface $bus,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(CommandSelectors::ARGUMENT_SIRET, InputArgument::OPTIONAL, 'SIRET of one auto-entrepreneur to track or refresh (default: the least recently synced ones)');
        CommandOptions::addTenant($this, 'Tenant id (default: every tenant; with a SIRET, the only configured tenant)');
        $this->addOption(self::OPTION_WITH_BIRTH_DATE, null, InputOption::VALUE_NONE, 'Also store the birth date of the auto-entrepreneur (asked in a hidden prompt, with a SIRET)');
        CommandOptions::addLimit($this);
        CommandOptions::addDryRun($this);
        $this->addOption(self::OPTION_ASYNC, null, InputOption::VALUE_NONE, 'Dispatch one Messenger message per tenant instead of refreshing now');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $siret = CommandOptions::argument($input, CommandSelectors::ARGUMENT_SIRET);
            $limit = CommandOptions::limit($input);
            $dryRun = CommandOptions::dryRun($input);
            $async = CommandOptions::flag($input, self::OPTION_ASYNC);
            $withBirthDate = CommandOptions::flag($input, self::OPTION_WITH_BIRTH_DATE);
            if ($siret !== null) {
                if ($limit !== null || $dryRun || $async) {
                    throw new InvalidOptionException('A SIRET cannot be combined with --limit, --dry-run or --async.');
                }

                return $this->syncSiret($input, $output, CommandSelectors::siret($input), $withBirthDate ? new QuestionHelper() : null);
            }
            if ($withBirthDate) {
                throw new InvalidOptionException('--with-birth-date needs a SIRET.');
            }
            if ($async) {
                return $dryRun ? throw new InvalidOptionException('--async cannot be combined with --dry-run.') : $this->dispatch($input, $output);
            }

            return $dryRun ? $this->plan($input, $output, $limit) : $this->syncTenants($input, $output, $limit);
        });
    }

    private function syncSiret(InputInterface $input, OutputInterface $output, Siret $siret, ?QuestionHelper $birthDatePrompt): int
    {
        $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);

        return $this->accountLocks->forSiret($output, $tenant, $siret, OperationLocks::ACCOUNT_SYNC, function () use ($input, $output, $tenant, $siret, $birthDatePrompt): int {
            $birthDate = $birthDatePrompt instanceof QuestionHelper ? CommandPersonalInputs::birthDate($input, $output, $birthDatePrompt) : null;
            CommandReports::renderFields($output, CommandViews::account($this->trackOrSync($tenant, $siret, $birthDate)));

            return Command::SUCCESS;
        });
    }

    /**
     * Tracks an untracked SIRET (and stores the birth date of a tracked one), then refreshes a tracked one.
     */
    private function trackOrSync(TenantId $tenant, Siret $siret, ?CalendarDate $birthDate): AccountView
    {
        if (!$this->isTracked($tenant, $siret)) {
            return ($this->track)(new TrackAccount($tenant, $siret, $birthDate));
        }
        if ($birthDate instanceof CalendarDate) {
            ($this->track)(new TrackAccount($tenant, $siret, $birthDate));
        }

        return ($this->syncOne)(new SyncAccount($tenant, AccountSelector::bySiret($siret)));
    }

    private function isTracked(TenantId $tenant, Siret $siret): bool
    {
        try {
            ($this->getAccount)(new GetAccount($tenant, AccountSelector::bySiret($siret)));

            return true;
        } catch (RecordNotFound) {
            return false;
        }
    }

    private function syncTenants(InputInterface $input, OutputInterface $output, ?int $limit): int
    {
        $report = BatchReport::of();
        foreach ($this->tenants($input) as $tenant) {
            $report = $report->merge($this->locks->exclusively(
                OperationLocks::ACCOUNTS_SYNC,
                OperationLocks::tenantScope($tenant),
                fn(): BatchReport => ($this->syncTenant)(new SyncAccounts($tenant, $limit)),
                static function (string $resource) use ($output): BatchReport {
                    CommandReports::renderBusy($output, $resource);

                    return BatchReport::of();
                },
            ));
        }

        return CommandReports::renderReport($output, $report);
    }

    private function dispatch(InputInterface $input, OutputInterface $output): int
    {
        $bus = $this->bus ?? throw new InvalidOptionException('--async requires "messenger.enabled".');
        $tenants = $this->tenants($input);
        foreach ($tenants as $tenant) {
            $bus->dispatch(new SyncAccountsMessage($tenant->value()));
        }
        $output->writeln(\sprintf('%d message(s) dispatched.', \count($tenants)));

        return Command::SUCCESS;
    }

    private function plan(InputInterface $input, OutputInterface $output, ?int $limit): int
    {
        $rows = [];
        foreach ($this->tenants($input) as $tenant) {
            foreach ($this->accounts->findLeastRecentlySynced($tenant, $this->policy->pageSize($limit)) as $account) {
                $rows[] = [$tenant->value(), $account->id()->value()];
            }
        }

        return CommandReports::renderPlan($output, $rows);
    }

    /**
     * @return list<TenantId> the --tenant, else every tenant with tracked accounts
     */
    private function tenants(InputInterface $input): array
    {
        $tenant = CommandOptions::tenant($input);

        return $tenant instanceof TenantId ? [$tenant] : $this->accounts->tenants();
    }
}
