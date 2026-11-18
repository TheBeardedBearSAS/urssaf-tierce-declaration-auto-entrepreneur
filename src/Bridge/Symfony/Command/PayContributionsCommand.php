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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Input\PaymentOrderFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;

/**
 * Orders the payment of the contributions of a period (method 060, ADR 0009). The payment reference is the idempotency
 * key. The period and the amount come from a JSON file (--payload: "period" and "amount"); the RUM is an option and the
 * IBAN, with --with-iban, a hidden prompt (ADR 0010): neither is accepted in the file. No lock: the claim of the record is
 * the atomic guard against a double order. Exits with 0 only when the payment is paid (now, or by an earlier run); any
 * other outcome needs an operator and exits with 1. A payment whose answer was lost is never resent.
 */
#[AsCommand(name: 'urssaf:tdae:pay', description: 'Order the payment of the contributions of a period (method 060)')]
final class PayContributionsCommand extends Command
{
    private const ARGUMENT_REFERENCE = 'payment-reference';
    private const OPTION_PAYLOAD = 'payload';
    private const OPTION_RUM = 'rum';
    private const OPTION_WITH_IBAN = 'with-iban';
    private const OPTION_ALLOW_ADDITIONAL_PAYMENT = 'allow-additional-payment';
    private const OPTION_SKIP_MANDATE_CHECK = 'skip-mandate-check';
    private const REFUSED_KEYS = ['iban' => '--with-iban', 'rum' => '--rum'];

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly PayContributionsHandler $handler,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addSiret($this);
        $this->addArgument(self::ARGUMENT_REFERENCE, InputArgument::REQUIRED, 'Payment reference (your idempotency key)');
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        CommandPayloads::add($this, self::OPTION_PAYLOAD, 'JSON file with the payment (required): "period" and "amount" (see Domain\Payment\Input\PaymentOrderFactory)');
        $this->addOption(self::OPTION_RUM, null, InputOption::VALUE_REQUIRED, 'RUM of the SEPA mandate to debit (default: the one of the auto-entrepreneur)');
        $this->addOption(self::OPTION_WITH_IBAN, null, InputOption::VALUE_NONE, 'Give the IBAN to debit (asked in a hidden prompt)');
        $this->addOption(self::OPTION_ALLOW_ADDITIONAL_PAYMENT, null, InputOption::VALUE_NONE, 'Allow a second payment for a period that already has one');
        $this->addOption(self::OPTION_SKIP_MANDATE_CHECK, null, InputOption::VALUE_NONE, 'Do not require an active local mandate (when "workflow.require_local_mandate" is on)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $siret = CommandSelectors::siret($input);
            $reference = PaymentReference::fromString(CommandOptions::argument($input, self::ARGUMENT_REFERENCE) ?? '');
            $fields = CommandPayloads::require($input, self::OPTION_PAYLOAD);
            foreach (self::REFUSED_KEYS as $key => $option) {
                if (\array_key_exists($key, $fields)) {
                    throw new InvalidOptionException(\sprintf('The --payload file must not hold "%s": use %s.', $key, $option));
                }
            }
            $rum = CommandOptions::text($input, self::OPTION_RUM);
            if ($rum !== null) {
                $fields['rum'] = $rum;
            }
            $factory = new PaymentOrderFactory();
            $order = $factory->fromInput($fields);
            if (CommandOptions::flag($input, self::OPTION_WITH_IBAN)) {
                $fields['iban'] = CommandPersonalInputs::iban($input, $output, new QuestionHelper());
                $order = $factory->fromInput($fields);
            }

            $submission = ($this->handler)(new PayContributions(
                $tenant,
                $reference,
                $siret,
                $order,
                CommandOptions::flag($input, self::OPTION_SKIP_MANDATE_CHECK),
                CommandOptions::flag($input, self::OPTION_ALLOW_ADDITIONAL_PAYMENT),
            ));
            CommandReports::renderFields($output, [...CommandViews::payment($submission->payment), ['Outcome', $submission->outcome->value]]);

            $state = $submission->payment->status->state;
            $hint = match ($state) {
                PaymentState::OutcomeUnknown->value => 'The answer was lost: never order it again. Check on the auto-entrepreneur space, then run urssaf:tdae:payments:resolve.',
                PaymentState::InvalidCredentials->value => 'Fix the credentials, then run urssaf:tdae:requeue and order the payment again.',
                PaymentState::Rejected->value => 'URSSAF refused the payment: see the error codes, fix the data and order it again under a new reference.',
                default => null,
            };
            if ($hint !== null) {
                $output->writeln(\sprintf('<comment>%s</comment>', $hint));
            }

            return $submission->outcome === ItemOutcome::Paid || ($submission->outcome === ItemOutcome::Replayed && $state === PaymentState::Paid->value)
                ? Command::SUCCESS
                : Command::FAILURE;
        });
    }
}
