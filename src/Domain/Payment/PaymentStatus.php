<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment;

use DateInterval;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;

/**
 * Current status of a {@see PaymentRecord}: state, submission attempts (count and start of the last one), causes of
 * the last refusal or failure, result once paid, reason of an unknown outcome, last update. Immutable; every change
 * goes through the state machine of {@see PaymentState}.
 *
 * Invariants: the attempt count is not negative; a paid record has its result; an unknown outcome has its reason.
 *
 * The public constructor is the factory (more than 5 parts). One accessor per field and one transition per command, hence
 * the method count.
 *
 * @mago-expect lint:too-many-methods One accessor per field and one transition per command.
 */
final readonly class PaymentStatus
{
    private const AGGREGATE = 'payment_record';

    /**
     * @param list<SubmissionError> $errors
     *
     * @throws InvariantViolation "payment_record.negative_attempts", "payment_record.result_required"
     *                            or "payment_record.outcome_unknown_reason_required"
     */
    public function __construct(
        private PaymentState $state,
        private int $attempts,
        private ?Instant $lastAttemptAt,
        private array $errors,
        private ?PaymentResult $result,
        private ?OutcomeUnknownReason $outcomeUnknownReason,
        private Instant $updatedAt,
    ) {
        if ($attempts < 0) {
            throw InvariantViolation::because(
                self::AGGREGATE . '.negative_attempts',
                'The number of submission attempts must not be negative.',
                ['attempts' => $attempts],
            );
        }
        if (!$result instanceof PaymentResult && $state === PaymentState::Paid) {
            throw InvariantViolation::because(
                self::AGGREGATE . '.result_required',
                'A paid record requires the result.',
            );
        }
        if (!$outcomeUnknownReason instanceof OutcomeUnknownReason && $state === PaymentState::OutcomeUnknown) {
            throw InvariantViolation::because(
                self::AGGREGATE . '.outcome_unknown_reason_required',
                'A record with an unknown outcome requires the reason.',
            );
        }
    }

    /**
     * pending -> submitting: one more attempt, started now.
     *
     * @throws InvalidStateTransition
     */
    public function submissionStarted(Instant $now): self
    {
        return new self(
            $this->state->transitionTo(PaymentState::Submitting),
            $this->attempts + 1,
            $now,
            $this->errors,
            $this->result,
            null,
            $now,
        );
    }

    /**
     * submitting -> paid (060 succeeded), or the late acceptance of an answer that arrives on another state
     * (see {@see PaymentState}): stores the result, clears the errors and the reason of an unknown outcome.
     *
     * @throws InvalidStateTransition from paid (the first result is kept)
     */
    public function paid(PaymentResult $result, Instant $now): self
    {
        return $this->moved(PaymentState::Paid, $now, [], $result);
    }

    /**
     * outcome_unknown -> paid: the operator found the payment on the URSSAF portal; stores the result (the
     * reference only), clears the errors.
     *
     * @throws InvalidStateTransition
     */
    public function resolvedAsPaid(PaymentResult $result, Instant $now): self
    {
        $this->assertState(PaymentState::OutcomeUnknown, PaymentState::Paid);

        return $this->moved(PaymentState::Paid, $now, [], $result);
    }

    /**
     * submitting -> rejected (business or validation refusal): stores the errors.
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function rejected(array $errors, Instant $now): self
    {
        return $this->moved(PaymentState::Rejected, $now, $errors, $this->result);
    }

    /**
     * submitting -> error (failure proven not sent): stores the errors.
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function failed(array $errors, Instant $now): self
    {
        return $this->moved(PaymentState::Error, $now, $errors, $this->result);
    }

    /**
     * submitting -> outcome_unknown: stores the reason and the errors.
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function outcomeUnknown(OutcomeUnknownReason $reason, array $errors, Instant $now): self
    {
        return new self(
            $this->state->transitionTo(PaymentState::OutcomeUnknown),
            $this->attempts,
            $this->lastAttemptAt,
            $errors,
            $this->result,
            $reason,
            $now,
        );
    }

    /**
     * submitting -> invalid_credentials: clears the errors.
     *
     * @throws InvalidStateTransition
     */
    public function credentialsRejected(Instant $now): self
    {
        return $this->moved(PaymentState::InvalidCredentials, $now, [], $this->result);
    }

    /**
     * error or invalid_credentials -> pending: the errors are kept. An unknown outcome is never retried, see
     * {@see self::resolvedAsNotReceived()}.
     *
     * @throws InvalidStateTransition
     */
    public function retried(Instant $now): self
    {
        if ($this->state === PaymentState::OutcomeUnknown) {
            throw InvalidStateTransition::between(self::AGGREGATE, $this->state->value, PaymentState::Pending->value);
        }

        return $this->moved(PaymentState::Pending, $now, $this->errors, $this->result);
    }

    /**
     * outcome_unknown -> pending: the operator checked that URSSAF did not receive the payment. The errors are
     * kept, the reason is cleared.
     *
     * @throws InvalidStateTransition
     */
    public function resolvedAsNotReceived(Instant $now): self
    {
        $this->assertState(PaymentState::OutcomeUnknown, PaymentState::Pending);

        return $this->moved(PaymentState::Pending, $now, $this->errors, $this->result);
    }

    public function state(): PaymentState
    {
        return $this->state;
    }

    /**
     * Number of submission attempts (pending -> submitting) so far.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Start of the last submission attempt; null while never submitted.
     */
    public function lastAttemptAt(): ?Instant
    {
        return $this->lastAttemptAt;
    }

    /**
     * Causes of the last refusal or failure.
     *
     * @return list<SubmissionError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Null while not paid.
     */
    public function result(): ?PaymentResult
    {
        return $this->result;
    }

    public function outcomeUnknownReason(): ?OutcomeUnknownReason
    {
        return $this->outcomeUnknownReason;
    }

    public function updatedAt(): Instant
    {
        return $this->updatedAt;
    }

    /**
     * True while fewer than maxAttempts submission attempts were made.
     */
    public function hasAttemptsLeft(int $maxAttempts): bool
    {
        return $this->attempts < $maxAttempts;
    }

    /**
     * True when the last submission attempt started at or before now - age (false when never started).
     */
    public function isLastAttemptStartedBefore(Instant $now, DateInterval $age): bool
    {
        return $this->lastAttemptAt instanceof Instant
            && !Instant::fromDateTime($this->lastAttemptAt->toDateTimeImmutable()->add($age))->isAfter($now);
    }

    /**
     * @throws InvalidStateTransition when the status is not in the required state
     */
    private function assertState(PaymentState $required, PaymentState $target): void
    {
        if ($this->state !== $required) {
            throw InvalidStateTransition::between(self::AGGREGATE, $this->state->value, $target->value);
        }
    }

    /**
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    private function moved(PaymentState $target, Instant $now, array $errors, ?PaymentResult $result): self
    {
        return new self(
            $this->state->transitionTo($target),
            $this->attempts,
            $this->lastAttemptAt,
            $errors,
            $result,
            null,
            $now,
        );
    }
}
