<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;
use Throwable;

/**
 * Declares the turnover of a period (method 050) and returns what happened (ADR 0003, ADR 0009, ADR 0011).
 *
 * Local refusals (periodicity of the tracked account, mandate) come after the fingerprint is known and before any record
 * or claim, on the paths that send only. Re-entry for the same tenant, SIRET and period, by state of the current record
 * (a record holds a fingerprint of the declaration, never its amounts):
 * - none: recorded, then sent;
 * - pending: sent now (same fingerprint), or superseded then sent; submitting: {@see ItemOutcome::NotClaimed}, nothing is
 *   sent, whatever the content;
 * - error: sent again when attempts remain (policy raised), else {@see ItemOutcome::Exhausted} (same fingerprint); another
 *   content supersedes the record;
 * - declared, outcome unknown or invalid credentials: {@see ItemOutcome::Replayed}, nothing is sent (same fingerprint); another content
 *   supersedes the record (URSSAF keeps the last declaration of a period);
 * - rejected (URSSAF recorded nothing): superseded then sent, whatever the content.
 * A superseded record keeps its state and is linked to the new one. Concurrent calls (ADR 0009): the one that loses a race
 * sends nothing and returns {@see ItemOutcome::NotClaimed} with the record the winner holds, whether it hit the uniqueness
 * constraint of the current record while recording ("declaration_record.duplicate_current"), found the record changed
 * ("declaration_record.concurrent_modification") or lost the claim. The record keeps the tracked account id of the SIRET
 * at that time.
 *
 * One branch per row of the re-entry table of ADR 0009, hence the complexity.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class DeclareTurnoverHandler
{
    public const PERIODICITY_MISMATCH = 'declaration_period.periodicity_mismatch';

    public function __construct(
        private DeclarationRecordRepository $records,
        private AeAccountRepository $accounts,
        private DeclarationSubmitter $submitter,
        private MandateGuard $guard,
        private IdGenerator $ids,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws Throwable {@see ValidationFailed} "declaration_period.periodicity_mismatch" or {@see MandateRequired}
     *                   (nothing was recorded nor sent), {@see RetryableUrssafFailure} (the record is pending again), or an
     *                   unexpected failure of the call, rethrown after its outcome was recorded
     */
    public function __invoke(DeclareTurnover $command): DeclarationSubmission
    {
        $period = $command->declaration->period();
        $fingerprint = DeclarationFingerprint::of($command->tenantId, $command->siret, $period, $command->declaration->turnover());
        $account = $this->accounts->findBySiret($command->tenantId, $command->siret);
        $existing = $this->records->findCurrent($command->tenantId, $command->siret, $period);
        if (!$existing instanceof DeclarationRecord) {
            $this->assertGuards($command, $account);

            return $this->recordAndSubmit($command, $fingerprint, $account, null);
        }

        return $this->reenter($command, $fingerprint, $account, $existing);
    }

    /**
     * Re-entry for the same tenant, SIRET and period (see the class documentation): the rows of the re-entry table of
     * ADR 0009 that send run the guards first.
     */
    private function reenter(
        DeclareTurnover $command,
        DeclarationFingerprint $fingerprint,
        ?AeAccount $account,
        DeclarationRecord $existing,
    ): DeclarationSubmission {
        $same = $existing->hasSameFingerprintAs($fingerprint);
        if ($existing->state() === DeclarationState::Submitting) {
            return $this->described($existing, ItemOutcome::NotClaimed);
        }
        if ($same && $this->isSettledWithoutSending($existing)) {
            return $this->described($existing, $existing->state() === DeclarationState::Error ? ItemOutcome::Exhausted : ItemOutcome::Replayed);
        }
        $this->assertGuards($command, $account);
        if ($same && $existing->state() === DeclarationState::Pending) {
            return $this->submitter->submit($existing->id(), $command->tenantId, $command->siret, $command->declaration);
        }
        if ($same && $existing->state() === DeclarationState::Error) {
            return $this->requeueAndSend($command, $existing);
        }

        return $this->recordAndSubmit($command, $fingerprint, $account, $existing);
    }

    /**
     * The same declaration, in a state that sends nothing: declared, outcome unknown, invalid credentials (replayed), or
     * error with no attempt left (exhausted).
     */
    private function isSettledWithoutSending(DeclarationRecord $record): bool
    {
        $state = $record->state();
        if ($state === DeclarationState::Error) {
            return $record->isExhausted($this->policy->maxSubmissionAttempts());
        }

        return \in_array($state, [DeclarationState::Declared, DeclarationState::OutcomeUnknown, DeclarationState::InvalidCredentials], true);
    }

    private function described(DeclarationRecord $record, ItemOutcome $outcome): DeclarationSubmission
    {
        return new DeclarationSubmission(DeclarationView::fromRecord($record), $outcome);
    }

    /**
     * Periodicity guard, then mandate guard (ADR 0011 amendment).
     *
     * @throws ValidationFailed
     * @throws MandateRequired
     */
    private function assertGuards(DeclareTurnover $command, ?AeAccount $account): void
    {
        $period = $command->declaration->period();
        if (
            !$command->skipPeriodicityCheck
            && $account instanceof AeAccount
            && $account->periodicity() !== $period->periodicity()
        ) {
            throw ValidationFailed::withResult(ValidationResult::of(Violation::error(
                'period',
                self::PERIODICITY_MISMATCH,
                'The declaration period does not match the periodicity of the account (method 020).',
            )));
        }
        $this->guard->assertMandate($command->tenantId, $command->siret, Operation::Declare, $command->skipMandateCheck);
    }

    private function requeueAndSend(DeclareTurnover $command, DeclarationRecord $record): DeclarationSubmission
    {
        try {
            $this->unitOfWork->run(function (RecordedEvents $events) use ($record): void {
                $record->retry(Instant::fromDateTime($this->clock->now()), $this->policy->maxSubmissionAttempts());
                $this->records->save($record);
                $events->record(...$record->releaseEvents());
            });
        } catch (InvariantViolation $violation) {
            if ($violation->violationCode() !== DeclarationRecordRepository::CONCURRENT_MODIFICATION) {
                throw $violation;
            }

            try {
                $current = $this->records->get($record->id());
            } catch (RecordNotFound) {
                // Erased (ForgetAccount) by the concurrent writer: there is no record to describe, the conflict is the answer.
                throw $violation;
            }

            return $this->described($current, ItemOutcome::NotClaimed);
        }

        return $this->submitter->submit($record->id(), $command->tenantId, $command->siret, $command->declaration);
    }

    /**
     * Records the declaration (superseding the current record of the period, saved first: the new record takes the key)
     * once the guards accepted it, then sends it.
     */
    private function recordAndSubmit(
        DeclareTurnover $command,
        DeclarationFingerprint $fingerprint,
        ?AeAccount $account,
        ?DeclarationRecord $superseded,
    ): DeclarationSubmission {
        $id = DeclarationRecordId::fromString($this->ids->generate());
        $now = Instant::fromDateTime($this->clock->now());
        $record = $superseded instanceof DeclarationRecord
            ? $superseded->supersede($id, $fingerprint, $now)
            : DeclarationRecord::record(
                $id,
                $command->tenantId,
                $command->siret,
                $command->declaration->period(),
                $fingerprint,
                $account?->id(),
                $now,
            );
        // The superseded record is saved first: the new record takes over its key.
        $changed = $superseded instanceof DeclarationRecord ? [$superseded, $record] : [$record];

        try {
            $this->unitOfWork->run(function (RecordedEvents $events) use ($changed): void {
                foreach ($changed as $changedRecord) {
                    $this->records->save($changedRecord);
                    $events->record(...$changedRecord->releaseEvents());
                }
            });
        } catch (InvariantViolation $violation) {
            $winner = self::isRace($violation)
                ? $this->records->findCurrent($command->tenantId, $command->siret, $command->declaration->period())
                : null;
            if (!$winner instanceof DeclarationRecord) {
                throw $violation;
            }

            return $this->described($winner, ItemOutcome::NotClaimed);
        }

        return $this->submitter->submit($id, $command->tenantId, $command->siret, $command->declaration);
    }

    /**
     * Another writer recorded or changed the current record of the period meanwhile (ADR 0009).
     */
    private static function isRace(InvariantViolation $violation): bool
    {
        return $violation->violationCode() === DeclarationRecordRepository::DUPLICATE_CURRENT
            || $violation->violationCode() === DeclarationRecordRepository::CONCURRENT_MODIFICATION;
    }
}
