<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use Closure;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use Throwable;

/**
 * Claims a pending payment record, orders the payment (060) and settles the answer (ADR 0003, ADR 0009).
 *
 * The claim is atomic (compare-and-set pending -> submitting): a concurrent caller gets {@see ItemOutcome::NotClaimed}.
 * The URSSAF call is made outside any transaction and is **never resent** (060 is never retried, ADR 0003): a failure
 * that may have reached URSSAF is an unknown outcome, even when the gateway says it is retryable. Every answer is then
 * settled in a transaction of its own against the record **as re-read when the answer arrives**, the claim being
 * identified by the attempt number it took (settlement table of ADR 0009):
 * - accepted (200): recorded as paid on every surviving state but paid (late acceptance: reconciled meanwhile, resolved
 *   as not received, requeued, claimed again or rejected); on a paid record the first result is kept. It is never
 *   discarded: a record that was erased, a save that fails for another reason or conflicts that outlast
 *   {@see self::MAX_RECORDING_ATTEMPTS} attempts are a {@see PaymentRecordingFailed} (the payment is ordered, it is never
 *   ordered again);
 * - any other answer only settles its own claim (state submitting, same attempt): on a record it no longer owns it changes
 *   nothing and the caller gets the re-entry outcome of the current state ({@see ItemOutcome::NotClaimed},
 *   {@see ItemOutcome::Replayed}), without any exception. On an owned record:
 *   - rejected (400), invalid credentials (401 / 403), unknown outcome: returned;
 *   - failure proven not sent (429, token endpoint, local rate limit): error, then pending again while attempts remain
 *     ({@see RetryableUrssafFailure} thrown after the commit) or {@see ItemOutcome::Exhausted} returned;
 *   - the tenant has no credentials ("credentials.not_found", nothing sent): the same, and the failure is rethrown;
 *   - any other failure (another package exception, a bug, a foreign gateway): outcome unknown, rethrown unchanged.
 * Messages of port exceptions carry no personal data and a foreign exception's message is never stored (only its class).
 * The IBAN of the order is only passed to the call: it is never stored (ADR 0010).
 *
 * One private method per outcome of the settlement table, hence the method count and the complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class PaymentSubmitter
{
    public const MAX_RECORDING_ATTEMPTS = 3;

    public function __construct(
        private PaymentRecordRepository $records,
        private ContributionPayer $payer,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws Throwable {@see RetryableUrssafFailure} when the call provably did not reach URSSAF (the record is back to
     *                   pending, committed), {@see PaymentRecordingFailed} when an accepted payment could not be recorded,
     *                   or the unexpected failure itself, rethrown unchanged after its outcome was committed
     */
    public function submit(
        PaymentRecordId $id,
        TenantId $tenantId,
        Siret $siret,
        #[SensitiveParameter]
        PaymentOrder $order,
    ): PaymentSubmission {
        if (!$this->records->claimForSubmission($id, Instant::fromDateTime($this->clock->now()))) {
            return new PaymentSubmission(PaymentView::fromRecord($this->records->get($id)), ItemOutcome::NotClaimed);
        }
        $claimed = $this->records->get($id);
        try {
            $result = $this->payer->payContributions($tenantId, $siret, $order);
        } catch (Throwable $failure) {
            return $this->settleFailure($claimed, $failure);
        }

        return $this->paid($claimed, $result);
    }

    /**
     * An accepted 060 is never discarded: it is recorded on whichever state the record has when the answer arrives (see
     * {@see self::settle()}).
     */
    private function paid(PaymentRecord $claimed, PaymentResult $result): PaymentSubmission
    {
        return $this->settle(
            $claimed,
            static function (PaymentRecord $record, Instant $now) use ($result): ItemOutcome {
                $record->markPaid($result, $now);

                return ItemOutcome::Paid;
            },
            $result,
        );
    }

    /**
     * @throws Throwable {@see RetryableUrssafFailure}, or the failure itself when it is neither a business answer nor
     *                   proven not sent
     */
    private function settleFailure(PaymentRecord $claimed, Throwable $failure): PaymentSubmission
    {
        $kind = SubmissionFailures::classify($failure, false);
        if ($kind === SubmissionFailureKind::InvalidCredentials) {
            return $this->invalidCredentials($claimed);
        }
        if ($kind === SubmissionFailureKind::OutcomeUnknown) {
            return $this->unknown($claimed, $failure);
        }
        if ($kind === SubmissionFailureKind::NotSent || $kind === SubmissionFailureKind::LocalRefusal) {
            return $this->requeued($claimed, $failure);
        }

        return $this->rejectedOrUnexpected($claimed, $failure);
    }

    /**
     * 060 is never resent: whatever is left is a rejection, or an unexpected failure that may have reached URSSAF (never a
     * "resendable" one, the classification is asked with mayResend = false): it is settled without a requeue (ADR 0003).
     *
     * @throws Throwable
     */
    private function rejectedOrUnexpected(PaymentRecord $claimed, Throwable $failure): PaymentSubmission
    {
        return $failure instanceof UrssafRequestRejected
            ? $this->rejected($claimed, $failure)
            : $this->rethrowing($this->unknown($claimed, $failure), $failure);
    }

    /**
     * Proven not sent (429, token endpoint, local rate limit, no credentials): error, then pending again while attempts
     * remain. A retryable failure then tells the caller to retry later; a local refusal reaches the caller itself.
     *
     * @throws Throwable
     */
    private function requeued(PaymentRecord $claimed, Throwable $failure): PaymentSubmission
    {
        $submission = $this->requeue($claimed, $failure);
        if (!self::settled($submission)) {
            return $submission;
        }
        if (!$failure instanceof RetryableException) {
            throw $failure;
        }

        return $submission->outcome === ItemOutcome::RetryLater ? throw RetryableUrssafFailure::causedBy($failure) : $submission;
    }

    /**
     * The failure itself reaches the caller once its outcome was committed; a record the claim no longer owns gives the
     * re-entry outcome instead.
     *
     * @throws Throwable
     */
    private function rethrowing(PaymentSubmission $submission, Throwable $failure): PaymentSubmission
    {
        return self::settled($submission) ? throw $failure : $submission;
    }

    private function invalidCredentials(PaymentRecord $claimed): PaymentSubmission
    {
        return $this->settle($claimed, static function (PaymentRecord $record, Instant $now): ItemOutcome {
            $record->markInvalidCredentials($now);

            return ItemOutcome::InvalidCredentials;
        }, null);
    }

    private function unknown(PaymentRecord $claimed, Throwable $failure): PaymentSubmission
    {
        $errors = UrssafErrorTranslator::failureErrors($failure);

        return $this->settle($claimed, static function (PaymentRecord $record, Instant $now) use ($errors): ItemOutcome {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, $errors, $now);

            return ItemOutcome::OutcomeUnknown;
        }, null);
    }

    private function rejected(PaymentRecord $claimed, UrssafRequestRejected $rejection): PaymentSubmission
    {
        $errors = UrssafErrorTranslator::rejectionErrors($rejection);

        return $this->settle($claimed, static function (PaymentRecord $record, Instant $now) use ($errors): ItemOutcome {
            $record->markRejected($errors, $now);

            return ItemOutcome::Rejected;
        }, null);
    }

    /**
     * submitting -> error, then pending again while attempts remain. {@see ItemOutcome::Exhausted} when they do not
     * (the record is left in error), {@see ItemOutcome::RetryLater} when it was requeued.
     */
    private function requeue(PaymentRecord $claimed, Throwable $failure): PaymentSubmission
    {
        $errors = UrssafErrorTranslator::failureErrors($failure);

        return $this->settle($claimed, function (PaymentRecord $record, Instant $now) use ($errors): ItemOutcome {
            $record->markFailed($errors, $now);
            $maxAttempts = $this->policy->maxSubmissionAttempts();
            if (!$record->canRetry($maxAttempts)) {
                return ItemOutcome::Exhausted;
            }
            $record->retry($now, $maxAttempts);

            return ItemOutcome::RetryLater;
        }, null);
    }

    /**
     * True when the answer changed the record (the claim was still its own, or the answer was accepted).
     */
    private static function settled(PaymentSubmission $submission): bool
    {
        return $submission->outcome !== ItemOutcome::NotClaimed && $submission->outcome !== ItemOutcome::Replayed;
    }

    /**
     * Settles the answer of a call against the record as it is stored now, in one transaction (save, events), and
     * describes the record with the outcome. A conflict (another writer saved in between) re-reads and settles again,
     * at most {@see self::MAX_RECORDING_ATTEMPTS} times; the last conflict, or any other failure of the recording, is
     * rethrown unchanged. When the answer is accepted ($accepted) a failure of the recording is instead a
     * {@see PaymentRecordingFailed}, the payment being ordered and never ordered again.
     *
     * @param Closure(PaymentRecord, Instant): ItemOutcome $transition
     * @param-immediately-invoked-callable $transition
     */
    private function settle(PaymentRecord $claimed, Closure $transition, ?PaymentResult $accepted): PaymentSubmission
    {
        for ($attempt = 1; $attempt < self::MAX_RECORDING_ATTEMPTS; ++$attempt) {
            try {
                return $this->settleOnce($claimed, $transition, $accepted);
            } catch (Throwable $failure) {
                if (!self::isConflict($failure)) {
                    throw self::unrecorded($claimed, $accepted, $attempt, $failure);
                }
            }
        }

        try {
            return $this->settleOnce($claimed, $transition, $accepted);
        } catch (Throwable $failure) {
            throw self::unrecorded($claimed, $accepted, self::MAX_RECORDING_ATTEMPTS, $failure);
        }
    }

    private static function isConflict(Throwable $failure): bool
    {
        return $failure instanceof InvariantViolation
            && $failure->violationCode() === PaymentRecordRepository::CONCURRENT_MODIFICATION;
    }

    /**
     * What the caller gets when the settlement failed: the failure itself, except for an accepted answer, which must
     * never be lost silently: the payment is ordered and never ordered again.
     */
    private static function unrecorded(PaymentRecord $claimed, ?PaymentResult $accepted, int $attempts, Throwable $failure): Throwable
    {
        return $accepted instanceof PaymentResult
            ? PaymentRecordingFailed::after(
                $claimed->id(),
                $accepted,
                $attempts,
                $failure instanceof DomainException ? $failure->violationCode() : $failure::class,
            )
            : $failure;
    }

    /**
     * @param Closure(PaymentRecord, Instant): ItemOutcome $transition
     */
    private function settleOnce(PaymentRecord $claimed, Closure $transition, ?PaymentResult $accepted): PaymentSubmission
    {
        try {
            $record = $this->records->get($claimed->id());
        } catch (RecordNotFound $erased) {
            // Erased (ForgetAccount): an accepted result has nowhere to go, any other answer has nothing to settle.
            if ($accepted instanceof PaymentResult) {
                throw $erased;
            }

            return new PaymentSubmission(PaymentView::fromRecord($claimed), ItemOutcome::NotClaimed);
        }
        if ($accepted instanceof PaymentResult && $record->state() === PaymentState::Paid) {
            // First result kept.
            return new PaymentSubmission(PaymentView::fromRecord($record), ItemOutcome::Paid);
        }
        if (!$accepted instanceof PaymentResult && !self::owns($claimed, $record)) {
            return new PaymentSubmission(PaymentView::fromRecord($record), self::reentry($record->state()));
        }
        $outcome = $this->unitOfWork->run(function (RecordedEvents $events) use ($record, $transition): ItemOutcome {
            $outcome = $transition($record, Instant::fromDateTime($this->clock->now()));
            $this->records->save($record);
            $events->record(...$record->releaseEvents());

            return $outcome;
        });

        return new PaymentSubmission(PaymentView::fromRecord($record), $outcome);
    }

    /**
     * The claim is identified by the attempt number it took: it owns the record while it is submitting that attempt.
     */
    private static function owns(PaymentRecord $claimed, PaymentRecord $current): bool
    {
        return $current->state() === PaymentState::Submitting && $current->attempts() === $claimed->attempts();
    }

    /**
     * What PayContributions would answer to the same payment: in flight or waiting (not claimed), or already settled
     * (replayed).
     */
    private static function reentry(PaymentState $state): ItemOutcome
    {
        return match ($state) {
            PaymentState::Pending, PaymentState::Submitting, PaymentState::Error => ItemOutcome::NotClaimed,
            PaymentState::Paid,
            PaymentState::Rejected,
            PaymentState::OutcomeUnknown,
            PaymentState::InvalidCredentials => ItemOutcome::Replayed,
        };
    }
}
