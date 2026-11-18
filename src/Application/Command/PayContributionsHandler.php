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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentAlreadyRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;
use Throwable;

/**
 * Orders a payment (method 060) and returns what happened (ADR 0003, ADR 0009, ADR 0011).
 *
 * Local refusals (another content for the reference, second payment, mandate) come before any record or claim.
 * Re-entry for the same tenant and payment reference, by state of the existing record (checked in this order):
 * - submitting: {@see ItemOutcome::NotClaimed}, nothing is sent, whatever the content;
 * - rejected (URSSAF recorded nothing): superseded by a new record and sent again, with another amount, RUM or IBAN, but
 *   only for the same SIRET and the same period, otherwise {@see PaymentAlreadyRecorded}. The second-payment guard does
 *   not run: this is the re-entry of ADR 0009;
 * - another content: {@see PaymentAlreadyRecorded};
 * - pending: sent now (after the mandate guard); error: sent again when attempts remain, else
 *   {@see ItemOutcome::Exhausted}; paid, outcome unknown or invalid credentials: {@see ItemOutcome::Replayed}, nothing
 *   is sent.
 * A new reference runs the second-payment guard (the period has no payment submitting, paid or with an unknown outcome,
 * unless "allowAdditionalPayment"), then the mandate guard.
 * Concurrent calls: the one that loses a race sends nothing and returns {@see ItemOutcome::NotClaimed} with the record the
 * winner holds.
 * The IBAN is only passed to URSSAF, never stored.
 *
 * One private method per re-entry row and per guard, hence the method count and the complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class PayContributionsHandler
{
    public const PERIOD_ALREADY_PAID = 'payment.period_already_paid';

    private const PERIOD_STATES = [PaymentState::Submitting, PaymentState::Paid, PaymentState::OutcomeUnknown];

    public function __construct(
        private PaymentRecordRepository $records,
        private AeAccountRepository $accounts,
        private PaymentSubmitter $submitter,
        private MandateGuard $guard,
        private IdGenerator $ids,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws Throwable {@see PaymentAlreadyRecorded}, {@see ValidationFailed} (second payment), {@see MandateRequired}
     *                   (nothing was recorded nor sent), {@see RetryableUrssafFailure} (the record is pending again), or
     *                   an unexpected failure of the call, rethrown after its outcome was recorded
     */
    public function __invoke(PayContributions $command): PaymentSubmission
    {
        $existing = $this->records->findByReference($command->tenantId, $command->reference);
        if (!$existing instanceof PaymentRecord) {
            $this->assertSinglePayment($command);
            $this->assertMandate($command);

            return $this->recordAndSubmit($command, null);
        }

        return $this->reenter($command, $existing);
    }

    private function reenter(PayContributions $command, PaymentRecord $existing): PaymentSubmission
    {
        return match ($existing->state()) {
            PaymentState::Submitting => $this->described($existing, ItemOutcome::NotClaimed),
            PaymentState::Rejected => $this->reenterRejected($command, $existing),
            PaymentState::Pending,
            PaymentState::Error,
            PaymentState::Paid,
            PaymentState::OutcomeUnknown,
            PaymentState::InvalidCredentials => $this->reenterSettled($command, $existing),
        };
    }

    /**
     * Another content for the reference is refused; the same content is sent (pending, error with attempts left) or
     * answered from the record (exhausted, paid, outcome unknown, invalid credentials).
     */
    private function reenterSettled(PayContributions $command, PaymentRecord $existing): PaymentSubmission
    {
        $fingerprint = PaymentFingerprint::of($command->tenantId, $command->siret, $command->order);
        if (!$existing->hasSameFingerprintAs($fingerprint)) {
            throw PaymentAlreadyRecorded::withReference($command->reference, $existing->id());
        }
        $state = $existing->state();
        if ($state === PaymentState::Pending) {
            return $this->send($command, $existing->id());
        }
        if ($state === PaymentState::Error) {
            return $existing->canRetry($this->policy->maxSubmissionAttempts())
                ? $this->requeueAndSend($command, $existing)
                : $this->described($existing, ItemOutcome::Exhausted);
        }

        return $this->described($existing, ItemOutcome::Replayed);
    }

    /**
     * A rejected payment is superseded, but a reference stays bound to its SIRET and its period (ADR 0011): only the
     * amount, the RUM and the IBAN may change.
     */
    private function reenterRejected(PayContributions $command, PaymentRecord $rejected): PaymentSubmission
    {
        if (!$rejected->siret()->equals($command->siret) || !$rejected->period()->equals($command->order->period())) {
            throw PaymentAlreadyRecorded::withReference($command->reference, $rejected->id());
        }
        $this->assertMandate($command);

        return $this->recordAndSubmit($command, $rejected);
    }

    /**
     * A period that already has a payment submitting, paid or with an unknown outcome is not paid twice by mistake.
     *
     * @throws ValidationFailed "payment.period_already_paid"
     */
    private function assertSinglePayment(PayContributions $command): void
    {
        if ($command->allowAdditionalPayment) {
            return;
        }
        $current = $this->records->findCurrentForPeriod(
            $command->tenantId,
            $command->siret,
            $command->order->period(),
            self::PERIOD_STATES,
        );
        if ($current !== []) {
            throw ValidationFailed::withResult(ValidationResult::of(Violation::error(
                'period',
                self::PERIOD_ALREADY_PAID,
                'The period already has a payment submitting, paid or with an unknown outcome: use allowAdditionalPayment for an intended second payment (ADR 0011).',
            )));
        }
    }

    /**
     * @throws MandateRequired
     */
    private function assertMandate(PayContributions $command): void
    {
        $this->guard->assertMandate($command->tenantId, $command->siret, Operation::Pay, $command->skipMandateCheck);
    }

    private function described(PaymentRecord $record, ItemOutcome $outcome): PaymentSubmission
    {
        return new PaymentSubmission(PaymentView::fromRecord($record), $outcome);
    }

    private function send(PayContributions $command, PaymentRecordId $id): PaymentSubmission
    {
        $this->assertMandate($command);

        return $this->submitter->submit($id, $command->tenantId, $command->siret, $command->order);
    }

    private function requeueAndSend(PayContributions $command, PaymentRecord $record): PaymentSubmission
    {
        $this->assertMandate($command);

        try {
            $this->unitOfWork->run(function (RecordedEvents $events) use ($record): void {
                $record->retry(Instant::fromDateTime($this->clock->now()), $this->policy->maxSubmissionAttempts());
                $this->records->save($record);
                $events->record(...$record->releaseEvents());
            });
        } catch (InvariantViolation $violation) {
            if ($violation->violationCode() !== PaymentRecordRepository::CONCURRENT_MODIFICATION) {
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

        return $this->submitter->submit($record->id(), $command->tenantId, $command->siret, $command->order);
    }

    /**
     * Records the payment (superseding a rejected record, saved first: strict key rule) once the guards accepted it, then
     * sends it.
     */
    private function recordAndSubmit(PayContributions $command, ?PaymentRecord $superseded): PaymentSubmission
    {
        $id = PaymentRecordId::fromString($this->ids->generate());

        try {
            $this->unitOfWork->run(function (RecordedEvents $events) use ($command, $superseded, $id): void {
                $now = Instant::fromDateTime($this->clock->now());
                $record = $superseded instanceof PaymentRecord
                    ? $this->supersede($superseded, $command, $id, $now)
                    : $this->record($command, $id, $now);
                $this->records->save($record);
                $events->record(...$record->releaseEvents());
            });
        } catch (InvariantViolation $violation) {
            $winner = self::isRace($violation)
                ? $this->records->findByReference($command->tenantId, $command->reference)
                : null;
            if (!$winner instanceof PaymentRecord) {
                throw $violation;
            }

            return $this->described($winner, ItemOutcome::NotClaimed);
        }

        return $this->submitter->submit($id, $command->tenantId, $command->siret, $command->order);
    }

    /**
     * A concurrent writer recorded or changed the payment of the reference first (ADR 0009).
     */
    private static function isRace(InvariantViolation $violation): bool
    {
        $code = $violation->violationCode();

        return $code === PaymentRecordRepository::DUPLICATE_REFERENCE || $code === PaymentRecordRepository::CONCURRENT_MODIFICATION;
    }

    /**
     * The superseded record is saved first (it is no longer current), the caller saves the new one.
     */
    private function supersede(
        PaymentRecord $superseded,
        PayContributions $command,
        PaymentRecordId $id,
        Instant $now,
    ): PaymentRecord {
        $record = $superseded->supersede($id, $command->order, $now);
        $this->records->save($superseded);

        return $record;
    }

    private function record(PayContributions $command, PaymentRecordId $id, Instant $now): PaymentRecord
    {
        return PaymentRecord::record(
            $id,
            $command->tenantId,
            $command->reference,
            $command->siret,
            $command->order,
            $this->accounts->findBySiret($command->tenantId, $command->siret)?->id(),
            $now,
        );
    }
}
