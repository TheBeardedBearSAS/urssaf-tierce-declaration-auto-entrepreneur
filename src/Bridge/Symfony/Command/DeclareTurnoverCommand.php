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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverDeclarationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;

/**
 * Declares the turnover of an auto-entrepreneur for a period to URSSAF (method 050, ADR 0009). The declaration comes
 * from a JSON file (--payload, see Domain\Contribution\Input\TurnoverDeclarationFactory). No lock: the claim of the record
 * is the atomic guard against a double submission. Exits with 0 only when the declaration is declared (now, or by an
 * earlier run); any other outcome needs an operator and exits with 1. A declaration whose answer was lost is never
 * resent.
 */
#[AsCommand(name: 'urssaf:tdae:declare', description: 'Declare the turnover of an auto-entrepreneur to URSSAF (method 050)')]
final class DeclareTurnoverCommand extends Command
{
    private const OPTION_PAYLOAD = 'payload';
    private const OPTION_SKIP_MANDATE_CHECK = 'skip-mandate-check';
    private const OPTION_SKIP_PERIODICITY_CHECK = 'skip-periodicity-check';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly DeclareTurnoverHandler $handler,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandSelectors::addSiret($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        CommandPayloads::add($this, self::OPTION_PAYLOAD, 'JSON file with the declaration (required): see Domain\Contribution\Input\TurnoverDeclarationFactory');
        $this->addOption(self::OPTION_SKIP_MANDATE_CHECK, null, InputOption::VALUE_NONE, 'Do not require an active local mandate (when "workflow.require_local_mandate" is on)');
        $this->addOption(self::OPTION_SKIP_PERIODICITY_CHECK, null, InputOption::VALUE_NONE, 'Do not check the period against the periodicity of the tracked account');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandTenants::tenantOrSingleConfigured($input, $this->configuredTenants);
            $siret = CommandSelectors::siret($input);
            $declaration = (new TurnoverDeclarationFactory())->fromInput(CommandPayloads::require($input, self::OPTION_PAYLOAD));

            $submission = ($this->handler)(new DeclareTurnover(
                $tenant,
                $siret,
                $declaration,
                CommandOptions::flag($input, self::OPTION_SKIP_MANDATE_CHECK),
                CommandOptions::flag($input, self::OPTION_SKIP_PERIODICITY_CHECK),
            ));
            CommandReports::renderFields($output, [...CommandViews::declaration($submission->declaration), ['Outcome', $submission->outcome->value]]);

            $state = $submission->declaration->status->state;
            $hint = match ($state) {
                DeclarationState::OutcomeUnknown->value => 'The answer was lost: never send it again. Check on the auto-entrepreneur space, then run urssaf:tdae:declarations:resolve.',
                DeclarationState::InvalidCredentials->value => 'Fix the credentials, then run urssaf:tdae:requeue and declare again.',
                DeclarationState::Rejected->value => 'URSSAF refused the declaration: see the error codes, fix the data and declare again.',
                default => null,
            };
            if ($hint !== null) {
                $output->writeln(\sprintf('<comment>%s</comment>', $hint));
            }

            return $submission->outcome === ItemOutcome::Declared || ($submission->outcome === ItemOutcome::Replayed && $state === DeclarationState::Declared->value)
                ? Command::SUCCESS
                : Command::FAILURE;
        });
    }
}
