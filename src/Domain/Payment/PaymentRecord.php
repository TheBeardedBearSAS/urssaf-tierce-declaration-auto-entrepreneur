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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Aggregate root: local follow-up of the submission of one payment order (060) to URSSAF (ADR 0003, ADR 0009).
 *
 * Created with {@see self::record()}. The identity (id, tenant, payment reference, SIRET, period, amount, RUM,
 * fingerprint, creation instant) is immutable; the mutable part is the {@see PaymentStatus} (state machine:
 * {@see PaymentState}). The record never holds the IBAN, not even hashed (ADR 0010 item 2): a retry is made by the host
 * sending the same order again with its IBAN, whose fingerprint is compared with {@see self::hasSameFingerprintAs()}. The
 * period is stored as three scalars (periodicity, year, number, ADR 0016 item 4) and rebuilt by {@see self::period()}.
 * Every state change records a domain event, see {@see self::releaseEvents()}. Invalid transitions throw
 * {@see InvalidStateTransition} ("payment_record.invalid_state_transition"); the state and the events are then left
 * untouched.
 *
 * Only a rejected payment is superseded by a re-entry of the same payment reference ({@see self::supersede()}): the new
 * record is the current one of the reference, the previous one keeps its state and stops being current.
 *
 * Submission attempts are counted (pending -> submitting); {@see self::retry()} refuses to requeue a failed
 * submission once the maximum number of attempts is reached.
 *
 * Not final only because Doctrine generates lazy-loading proxies by extending the mapped entities: final by
 * convention, never extend it. For the same reason the identifier is neither readonly nor private: a proxy is created
 * with its identifier, which Doctrine assigns again (another instance of the same value) when the proxy is loaded. It is
 * never reassigned otherwise.
 *
 * The root exposes its identity, transitions and queries, hence the method count.
 *
 * @final
 *
 * @mago-expect lint:too-many-methods The root exposes its identity, transitions and queries.
 */
class PaymentRecord
{
    /**
     * Default maximum number of submission attempts before {@see self::retry()} refuses to requeue.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    private PaymentStatus $status;

    private readonly Periodicity $periodicity;

    private readonly int $periodYear;

    private readonly int $periodNumber;

    private ?PaymentRecordId $supersededBy = null;

    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * Persistence concurrency token (optimistic lock of the Doctrine bridge): read and written by the ORM through
     * reflection, never by the domain. It is not a domain concept and has no accessor.
     */
    // @mago-expect analysis:unused-property Doctrine optimistic-lock token, read and written by the ORM through reflection.
    private int $version = 1;

    private function __construct(
        protected PaymentRecordId $id,
        private readonly TenantId $tenantId,
        private readonly PaymentReference $reference,
        private readonly Siret $siret,
        DeclarationPeriod $period,
        private readonly Euros $amount,
        private readonly ?Rum $rum,
        private readonly PaymentFingerprint $fingerprint,
        private readonly ?AccountId $accountId,
        private readonly ?PaymentRecordId $supersedes,
        private readonly Instant $createdAt,
    ) {
        $this->periodicity = $period->periodicity();
        $this->periodYear = $period->year();
        $this->periodNumber = DeclarationPeriods::numberOf($period);
        $this->status = new PaymentStatus(PaymentState::Pending, 0, null, [], null, null, $createdAt);
    }

    /**
     * Records the payment order (state pending) and records {@see PaymentRecorded} (`supersedes` null). The amount and
     * the RUM of the order are kept, never its IBAN. The tracked account, when the SIRET is tracked, is kept for the
     * integration events (ADR 0014 item 3).
     *
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    public static function record(
        PaymentRecordId $id,
        TenantId $tenantId,
        PaymentReference $reference,
        Siret $siret,
        PaymentOrder $order,
        ?AccountId $accountId,
        Instant $now,
    ): self {
        return self::create($id, $tenantId, $reference, $siret, $order, $accountId, null, $now);
    }

    /**
     * Re-entry of a rejected payment (ADR 0009): creates the new current record (same tenant, payment reference, SIRET and
     * account, content of the new order), which supersedes this one, and links this one to it. This record keeps its
     * state. The caller saves both records in one unit of work.
     *
     * @throws InvariantViolation "payment_record.already_superseded" or "payment_record.supersede_requires_rejection"
     */
    public function supersede(PaymentRecordId $newId, PaymentOrder $order, Instant $now): self
    {
        if ($this->supersededBy instanceof PaymentRecordId) {
            throw InvariantViolation::because(
                'payment_record.already_superseded',
                'The payment record is already superseded.',
            );
        }
        if ($this->status->state() !== PaymentState::Rejected) {
            throw InvariantViolation::because(
                'payment_record.supersede_requires_rejection',
                'A payment is only superseded after a rejection.',
                ['state' => $this->status->state()->value],
            );
        }
        $this->supersededBy = $newId;

        return self::create($newId, $this->tenantId, $this->reference, $this->siret, $order, $this->accountId, $this->id, $now);
    }

    public function id(): PaymentRecordId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function reference(): PaymentReference
    {
        return $this->reference;
    }

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function amount(): Euros
    {
        return $this->amount;
    }

    /**
     * The SEPA mandate reference of the order, if it carried one.
     */
    public function rum(): ?Rum
    {
        return $this->rum;
    }

    public function period(): DeclarationPeriod
    {
        return DeclarationPeriods::fromParts($this->periodicity, $this->periodYear, $this->periodNumber);
    }

    public function periodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function fingerprint(): PaymentFingerprint
    {
        return $this->fingerprint;
    }

    /**
     * Null when the SIRET was not tracked when the payment was recorded.
     */
    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    /**
     * The record this one replaces after a re-entry; null for a first record of the payment reference.
     */
    public function supersedes(): ?PaymentRecordId
    {
        return $this->supersedes;
    }

    /**
     * The record that replaced this one; null while this record is the current one of the payment reference.
     */
    public function supersededBy(): ?PaymentRecordId
    {
        return $this->supersededBy;
    }

    public function isCurrent(): bool
    {
        return !$this->supersededBy instanceof PaymentRecordId;
    }

    public function createdAt(): Instant
    {
        return $this->createdAt;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function state(): PaymentState
    {
        return $this->status->state();
    }

    public function attempts(): int
    {
        return $this->status->attempts();
    }

    public function lastAttemptAt(): ?Instant
    {
        return $this->status->lastAttemptAt();
    }

    /**
     * @return list<SubmissionError>
     */
    public function errors(): array
    {
        return $this->status->errors();
    }

    /**
     * Null until paid.
     */
    public function result(): ?PaymentResult
    {
        return $this->status->result();
    }

    public function outcomeUnknownReason(): ?OutcomeUnknownReason
    {
        return $this->status->outcomeUnknownReason();
    }

    public function updatedAt(): Instant
    {
        return $this->status->updatedAt();
    }

    /**
     * Idempotency: true when the fingerprint is the one of the recorded order.
     */
    public function hasSameFingerprintAs(PaymentFingerprint $fingerprint): bool
    {
        return $this->fingerprint->equals($fingerprint);
    }

    /**
     * pending -> submitting: one more attempt, started now. Concurrent workers should rather use
     * {@see PaymentRecordRepository::claimForSubmission()}.
     *
     * @throws InvalidStateTransition
     */
    public function startSubmission(Instant $now): void
    {
        $this->status = $this->status->submissionStarted($now);
    }

    /**
     * submitting -> paid (060 succeeded). Clears the last errors.
     *
     * An accepted answer is never discarded: it is also recorded on a record that is no longer the claim's own, whatever
     * its state but paid (late acceptance, ADR 0009). Same event.
     *
     * @throws InvalidStateTransition from paid (the first result is kept)
     */
    public function markPaid(PaymentResult $result, Instant $now): void
    {
        $this->status = $this->status->paid($result, $now);
        $this->events[] = $this->orderedEvent($result, $now);
    }

    /**
     * submitting -> rejected (business or validation refusal).
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function markRejected(array $errors, Instant $now): void
    {
        $this->status = $this->status->rejected($errors, $now);
        $this->events[] = new PaymentRejected(
            $this->id,
            $this->tenantId,
            $this->reference,
            $this->accountId,
            $this->period(),
            self::codes($errors),
            $now,
        );
    }

    /**
     * submitting -> outcome_unknown (transport error, 5xx or unreadable answer on 060, or a stale submission): the request may have reached
     * URSSAF, so it is never resent automatically (ADR 0003).
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function markOutcomeUnknown(OutcomeUnknownReason $reason, array $errors, Instant $now): void
    {
        $this->status = $this->status->outcomeUnknown($reason, $errors, $now);
        $this->events[] = new PaymentOutcomeUnknown(
            $this->id,
            $this->tenantId,
            $this->reference,
            $this->accountId,
            $this->period(),
            $reason,
            $now,
        );
    }

    /**
     * submitting -> error: a retryable failure proven not sent (429, local rate limit).
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function markFailed(array $errors, Instant $now): void
    {
        $this->status = $this->status->failed($errors, $now);
        $this->events[] = new PaymentSubmissionFailed($this->id, $this->tenantId, self::codes($errors), $now);
    }

    /**
     * submitting -> invalid_credentials. Clears the last errors.
     *
     * @throws InvalidStateTransition
     */
    public function markInvalidCredentials(Instant $now): void
    {
        $this->status = $this->status->credentialsRejected($now);
        $this->events[] = new PaymentCredentialsRejected($this->id, $this->tenantId, $now);
    }

    /**
     * error -> pending (while attempts remain) or invalid_credentials -> pending (always). A record with an unknown
     * outcome is not retried: see {@see self::resolveAsNotReceived()}.
     *
     * @param positive-int $maxAttempts
     *
     * @throws InvariantViolation     "payment_record.max_attempts_reached"
     * @throws InvalidStateTransition
     */
    public function retry(Instant $now, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): void
    {
        if (!$this->canRetry($maxAttempts)) {
            throw InvariantViolation::because(
                'payment_record.max_attempts_reached',
                'The maximum number of submission attempts is reached.',
                ['attempts' => $this->status->attempts(), 'max_attempts' => $maxAttempts],
            );
        }
        $from = $this->status->state();
        $this->status = $this->status->retried($now);
        $this->events[] = new PaymentRequeued($this->id, $this->tenantId, $from, $now);
    }

    /**
     * False only for a failed submission (state error) that used all its attempts.
     *
     * @param positive-int $maxAttempts
     */
    public function canRetry(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): bool
    {
        return $this->status->state() !== PaymentState::Error || $this->status->hasAttemptsLeft($maxAttempts);
    }

    /**
     * A failed submission (state error) that used all its attempts.
     *
     * @param positive-int $maxAttempts
     */
    public function isExhausted(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): bool
    {
        return !$this->canRetry($maxAttempts);
    }

    /**
     * outcome_unknown -> paid: the operator found the payment order at URSSAF and read its `certificat` (nothing else
     * is known: no payment id, no due date).
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsPaid(Certificate $certificate, Instant $now): void
    {
        $result = PaymentResult::resolved($certificate);
        $this->status = $this->status->resolvedAsPaid($result, $now);
        $this->events[] = $this->orderedEvent($result, $now);
    }

    /**
     * outcome_unknown -> pending: the operator checked that URSSAF did not receive the payment order.
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsNotReceived(Instant $now): void
    {
        $from = $this->status->state();
        $this->status = $this->status->resolvedAsNotReceived($now);
        $this->events[] = new PaymentRequeued($this->id, $this->tenantId, $from, $now);
    }

    /**
     * True when the record is submitting and its last attempt started at or before now - staleAfter.
     */
    public function isStaleSubmission(Instant $now, DateInterval $staleAfter): bool
    {
        return $this->status->state() === PaymentState::Submitting
            && $this->status->isLastAttemptStartedBefore($now, $staleAfter);
    }

    /**
     * Returns the events recorded since the last call and forgets them.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    private static function create(
        PaymentRecordId $id,
        TenantId $tenantId,
        PaymentReference $reference,
        Siret $siret,
        PaymentOrder $order,
        ?AccountId $accountId,
        ?PaymentRecordId $supersedes,
        Instant $now,
    ): self {
        $record = new self(
            $id,
            $tenantId,
            $reference,
            $siret,
            $order->period(),
            $order->amount(),
            $order->rum(),
            PaymentFingerprint::of($tenantId, $siret, $order),
            $accountId,
            $supersedes,
            $now,
        );
        $record->events[] = new PaymentRecorded($id, $tenantId, $reference, $accountId, $order->period(), $supersedes, $now);

        return $record;
    }

    private function orderedEvent(PaymentResult $result, Instant $now): PaymentOrdered
    {
        return new PaymentOrdered(
            $this->id,
            $this->tenantId,
            $this->reference,
            $this->accountId,
            $this->period(),
            $result->certificate(),
            $result->paymentId(),
            $this->rum,
            $now,
        );
    }

    /**
     * @param list<SubmissionError> $errors
     *
     * @return list<string>
     */
    private static function codes(array $errors): array
    {
        return array_map(static fn(SubmissionError $error): string => $error->code(), $errors);
    }
}
