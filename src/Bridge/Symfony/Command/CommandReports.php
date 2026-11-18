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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\AccountErasureRefused;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\DeclarationRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentAlreadyRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;
use Throwable;

/**
 * Execution guard and output of the console commands. Nothing personal is printed (ADR 0010): identifiers, codes,
 * states, amounts and instants only; the messages and descriptions of URSSAF are never printed.
 *
 * @mago-expect lint:cyclomatic-complexity One catch arm per exception of the mapping table of ADR 0015 item 8.
 */
final class CommandReports
{
    /** Outcomes that make a command exit with a failure status (the other ones are successes). */
    private const FAILURES = [
        ItemOutcome::Rejected,
        ItemOutcome::OutcomeUnknown,
        ItemOutcome::RetryLater,
        ItemOutcome::Exhausted,
        ItemOutcome::NotClaimed,
        ItemOutcome::Failed,
        ItemOutcome::InvalidCredentials,
        ItemOutcome::ManualReview,
    ];

    /**
     * Runs a command body. An invalid usage (option, input refused by the domain) prints its errors and gives
     * Command::INVALID (2); any other package failure prints its message and gives Command::FAILURE (1) (ADR 0015
     * item 8).
     *
     * @mago-expect lint:halstead One catch arm per exception of the mapping table of ADR 0015 item 8.
     *
     * @param callable(): int $action
     * @param-immediately-invoked-callable $action
     */
    public static function guard(OutputInterface $output, callable $action): int
    {
        try {
            return $action();
        } catch (InvalidOptionException $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));

            return Command::INVALID;
        } catch (ValidationFailed $failure) {
            $errors = $failure->result()->errors();
            array_walk($errors, static fn(Violation $error) => $output->writeln(\sprintf('<error>%s: %s (%s)</error>', $error->path(), $error->message(), $error->code())));
            $output->writeln(\sprintf('<error>Invalid input: %d error(s).</error>', \count($errors)));

            return Command::INVALID;
        } catch (InvalidValue $failure) {
            $output->writeln(\sprintf('<error>%s (%s)</error>', $failure->getMessage(), $failure->violationCode()));

            return Command::INVALID;
        } catch (MandateRequired $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            $output->writeln('<comment>Use --skip-mandate-check, or record the mandate without any call with urssaf:tdae:mandate:import.</comment>');

            return Command::INVALID;
        } catch (PaymentAlreadyRecorded | RecordNotFound $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));

            return Command::INVALID;
        } catch (AccountErasureRefused $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            $hint = self::erasureHint($failure);
            if ($hint !== null) {
                $output->writeln(\sprintf('<comment>%s</comment>', $hint));
            }

            return Command::FAILURE;
        } catch (MandateRecordingFailed $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            $output->writeln(\sprintf('<comment>URSSAF accepted the request: do not run the command again. %s</comment>', self::mandateRecovery($failure)));

            return Command::FAILURE;
        } catch (DeclarationRecordingFailed $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            $output->writeln(\sprintf('Certificat: %s', $failure->certificate()));
            $declarationId = $failure->declarationId();
            if ($declarationId !== null) {
                $output->writeln(\sprintf('idDeclaration: %s', $declarationId));
            }
            $output->writeln('<comment>URSSAF accepted the declaration: never send it again. Run urssaf:tdae:declarations:reconcile, then urssaf:tdae:declarations:resolve --declared-as=<certificat>.</comment>');

            return Command::FAILURE;
        } catch (PaymentRecordingFailed $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            $output->writeln(\sprintf('Certificat: %s', $failure->certificate()));
            $paymentId = $failure->paymentId();
            if ($paymentId !== null) {
                $output->writeln(\sprintf('idTelepaiement: %s', $paymentId));
            }
            $output->writeln('<comment>URSSAF accepted the payment: never order it again. Run urssaf:tdae:payments:reconcile, then urssaf:tdae:payments:resolve --paid-as=<certificat>.</comment>');

            return Command::FAILURE;
        } catch (RetryableException $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            $retryAfter = self::retryAfterSeconds($failure);
            $output->writeln(\sprintf('<comment>Nothing was recorded as sent: run the command again%s.</comment>', $retryAfter === null ? ' later' : \sprintf(' in %d second(s) or more', $retryAfter)));

            return Command::FAILURE;
        } catch (UrssafException $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Prints the items of a report as a table followed by the count of each outcome; fails when an item failed.
     */
    public static function renderReport(OutputInterface $output, BatchReport $report): int
    {
        $table = new Table($output);
        $table->setHeaders(['Record', 'Outcome', 'Codes']);
        $table->setRows(array_map(
            static fn(BatchItem $item): array => [$item->subject, $item->outcome->value, implode(', ', $item->codes)],
            $report->items(),
        ));
        $table->render();

        $counts = array_map(
            static fn(ItemOutcome $outcome): string => \sprintf('%s: %d', $outcome->value, $report->count($outcome)),
            array_filter(ItemOutcome::cases(), static fn(ItemOutcome $outcome): bool => $report->count($outcome) > 0),
        );
        $output->writeln(\sprintf('%d record(s) processed%s.', $report->total(), $counts === [] ? '' : ' (' . implode(', ', $counts) . ')'));

        $failed = array_filter(self::FAILURES, static fn(ItemOutcome $outcome): bool => $report->count($outcome) > 0);

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Tells that the same operation is already running (its lock is held): nothing is done, which is not a failure.
     */
    public static function renderBusy(OutputInterface $output, string $resource): int
    {
        $output->writeln(\sprintf('<comment>Skipped: "%s" is held by another process.</comment>', $resource));

        return Command::SUCCESS;
    }

    /**
     * Prints what a dry run would process.
     *
     * @param list<array{string, string}> $rows tenant id and record identifier
     */
    public static function renderPlan(OutputInterface $output, array $rows): int
    {
        $table = new Table($output);
        $table->setHeaders(['Tenant', 'Record']);
        $table->setRows($rows);
        $table->render();
        $output->writeln(\sprintf('Dry run: %d record(s) would be processed, URSSAF was not called.', \count($rows)));

        return Command::SUCCESS;
    }

    /**
     * Prints a view as a two-column table.
     *
     * @param list<array{string, string}> $rows field and value
     */
    public static function renderFields(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['Field', 'Value']);
        $table->setRows($rows);
        $table->render();
    }

    public static function yesNo(?bool $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            null => '-',
        };
    }

    public static function orDash(?string $value): string
    {
        return $value ?? '-';
    }

    private static function mandateRecovery(MandateRecordingFailed $failure): string
    {
        return $failure->params()['operation'] === Operation::CancelMandate->value
            ? 'Record the cancellation without any call: urssaf:tdae:mandate:record-cancellation (same SIRET).'
            : 'Record the mandate without any call: urssaf:tdae:mandate:import (same SIRET).';
    }

    /**
     * The opt-in that would let the erasure through; none for a submission in flight (always refused).
     */
    private static function erasureHint(AccountErasureRefused $failure): ?string
    {
        return match ($failure->violationCode()) {
            'ae_account.forget_active_mandate' => 'Cancel the mandate first (urssaf:tdae:mandate:cancel), or pass --allow-active-mandate to erase it locally anyway.',
            'ae_account.forget_unknown_outcome' => 'Resolve the unknown outcomes first (urssaf:tdae:declarations:resolve, urssaf:tdae:payments:resolve), or pass --allow-unknown-outcomes to erase them anyway.',
            default => null,
        };
    }

    /**
     * The delay URSSAF or the local limiter asked for, looked up in the chain of causes.
     */
    private static function retryAfterSeconds(Throwable $failure): ?int
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof RateLimitExceeded || $cause instanceof UrssafUnavailable) {
                return $cause->retryAfterSeconds();
            }
        }

        return null;
    }
}
