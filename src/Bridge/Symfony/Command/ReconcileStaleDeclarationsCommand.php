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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarations;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Moves the declarations left "submitting" for longer than "workflow.stale_submission_after" to "outcome_unknown": they
 * are never resent (ADR 0003). Works for every tenant, under one global lock (ADR 0015 item 10). Exits with 1 when a
 * record was moved (an alert for the operator, who then checks on the auto-entrepreneur space and runs
 * urssaf:tdae:declarations:resolve). Safety cron: every 15 minutes.
 */
#[AsCommand(name: 'urssaf:tdae:declarations:reconcile', description: 'Move the declarations left submitting to outcome_unknown (never resent, ADR 0003)')]
final class ReconcileStaleDeclarationsCommand extends Command
{
    public function __construct(
        private readonly DeclarationRecordRepository $declarations,
        private readonly ReconcileStaleDeclarationsHandler $reconcile,
        private readonly Clock $clock,
        private readonly WorkflowPolicy $policy,
        private readonly CommandLocks $locks,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandOptions::addLimit($this);
        CommandOptions::addDryRun($this);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $limit = CommandOptions::limit($input);
            if (CommandOptions::dryRun($input)) {
                $olderThan = Instant::fromDateTime($this->clock->now()->sub($this->policy->staleSubmissionAfter()));

                return CommandReports::renderPlan($output, array_map(
                    static fn(DeclarationRecord $record): array => [$record->tenantId()->value(), $record->id()->value()],
                    $this->declarations->findStaleSubmissions($olderThan, $this->policy->pageSize($limit)),
                ));
            }

            return $this->locks->globally(
                $output,
                OperationLocks::DECLARATIONS_RECONCILE,
                fn(): int => CommandReports::renderReport($output, ($this->reconcile)(new ReconcileStaleDeclarations($limit))),
            );
        });
    }
}
