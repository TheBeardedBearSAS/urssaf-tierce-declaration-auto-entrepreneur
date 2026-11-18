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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcomeHandler;

/**
 * Settles a payment left in "outcome_unknown" after the operator checked it on the auto-entrepreneur space (ADR 0003):
 * either URSSAF holds it (give its certificat), or it never arrived (the record is pending again; ordering the payment
 * again is the host's decision alone). No call, no lock: the transition is guarded by the state and the version of the
 * record.
 */
#[AsCommand(name: 'urssaf:tdae:payments:resolve', description: 'Settle an outcome_unknown payment after a manual check (ADR 0003)')]
final class ResolvePaymentOutcomeCommand extends Command
{
    private const OPTION_PAID_AS = 'paid-as';
    private const OPTION_NOT_RECEIVED = 'not-received';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly ResolvePaymentOutcomeHandler $resolve,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addPaymentSelector($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        $this->addOption(self::OPTION_PAID_AS, null, InputOption::VALUE_REQUIRED, 'The certificat URSSAF gave to the payment');
        $this->addOption(self::OPTION_NOT_RECEIVED, null, InputOption::VALUE_NONE, 'URSSAF never received the payment');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $selector = CommandSelectors::paymentSelector($input);
            $paidAs = CommandOptions::text($input, self::OPTION_PAID_AS);
            $notReceived = CommandOptions::flag($input, self::OPTION_NOT_RECEIVED);
            $hasOutcome = $paidAs !== null;
            if ($hasOutcome === $notReceived) {
                throw new InvalidOptionException('Give one of --paid-as or --not-received.');
            }

            $payment = ($this->resolve)($hasOutcome
                ? ResolvePaymentOutcome::asPaid($tenant, $selector, $paidAs)
                : ResolvePaymentOutcome::asNotReceived($tenant, $selector));
            CommandReports::renderFields($output, CommandViews::payment($payment));
            if ($notReceived) {
                $output->writeln('The payment is pending again: only you decide to order it again with urssaf:tdae:pay.');
            }

            return Command::SUCCESS;
        });
    }
}
