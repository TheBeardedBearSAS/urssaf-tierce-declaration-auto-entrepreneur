<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration;

use DateInterval;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Aggregate root: local follow-up of the submission of one turnover declaration (050) to URSSAF (ADR 0003, ADR 0009).
 *
 * Created with {@see self::record()}. The identity (id, tenant, SIRET, period, fingerprint, creation instant) is
 * immutable; the mutable part is the {@see DeclarationStatus} (state machine: {@see DeclarationState}). The record never
 * holds the declared amounts (ADR 0010 item 2): a retry is made by the host sending the same declaration again, whose
 * fingerprint is compared with {@see self::hasSameFingerprintAs()}. The period is stored as three scalars (periodicity,
 * year, number, ADR 0016 item 4) and rebuilt by {@see self::period()}. Every state change records a domain event, see
 * {@see self::releaseEvents()}. Invalid transitions throw {@see InvalidStateTransition}
 * ("declaration_record.invalid_state_transition"); the state and the events are then left untouched.
 *
 * A re-entry with another content, or after a rejection, supersedes the record ({@see self::supersede()}): the new record
 * is the current one of the period (URSSAF's last-wins rule), the previous one keeps its state and stops being current.
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
class DeclarationRecord
{
    /**
     * Default maximum number of submission attempts before {@see self::retry()} refuses to requeue.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    private DeclarationStatus $status;

    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * Persistence concurrency token (optimistic lock of the Doctrine bridge): read and written by the ORM through
     * reflection, never by the domain. It is not a domain concept and has no accessor.
     */
    // @mago-expect analysis:unused-property Doctrine optimistic-lock token, read and written by the ORM through reflection.
    private int $version = 1;

    private function __construct(
        protected DeclarationRecordId $id,
        private readonly TenantId $tenantId,
        private readonly Siret $siret,
        private readonly Periodicity $periodicity,
        private readonly int $periodYear,
        private readonly int $periodNumber,
        private readonly DeclarationFingerprint $fingerprint,
        private readonly ?AccountId $accountId,
        private readonly ?DeclarationRecordId $supersedes,
        private readonly Instant $createdAt,
        private ?DeclarationRecordId $supersededBy,
        private bool $acceptedAfterSupersession,
    ) {
        $this->status = new DeclarationStatus(DeclarationState::Pending, 0, null, [], null, null, $createdAt);
    }

    /**
     * Records the declaration (state pending) and records {@see DeclarationRecorded} (`supersedes` null). The tracked
     * account, when the SIRET is tracked, is kept for the integration events (ADR 0014 item 3).
     *
     * @mago-expect lint:excessive-parameter-list One parameter per part of the record identity.
     */
    public static function record(
        DeclarationRecordId $id,
        TenantId $tenantId,
        Siret $siret,
        DeclarationPeriod $period,
        DeclarationFingerprint $fingerprint,
        ?AccountId $accountId,
        Instant $now,
    ): self {
        return self::create($id, $tenantId, $siret, $period, $fingerprint, $accountId, null, $now);
    }

    /**
     * Re-entry of the period (ADR 0009): creates the new current record (same tenant, SIRET, period and account), which
     * supersedes this one, and links this one to it. This record keeps its state. Refused while the record is
     * submitting (`not_claimed` row of the ADR 0009 re-entry table). From every other state, whether a re-entry is
     * allowed is an application decision; the caller saves both records in one unit of work.
     *
     * @throws InvariantViolation     "declaration_record.already_superseded"
     * @throws InvalidStateTransition from submitting
     */
    public function supersede(DeclarationRecordId $newId, DeclarationFingerprint $fingerprint, Instant $now): self
    {
        if ($this->supersededBy instanceof DeclarationRecordId) {
            throw InvariantViolation::because(
                'declaration_record.already_superseded',
                'The declaration record is already superseded.',
            );
        }
        if ($this->status->state() === DeclarationState::Submitting) {
            throw InvalidStateTransition::between('declaration_record', DeclarationState::Submitting->value, 'superseded');
        }
        $this->supersededBy = $newId;

        return self::create($newId, $this->tenantId, $this->siret, $this->period(), $fingerprint, $this->accountId, $this->id, $now);
    }

    public function id(): DeclarationRecordId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function period(): DeclarationPeriod
    {
        return DeclarationPeriods::fromParts($this->periodicity, $this->periodYear, $this->periodNumber);
    }

    public function periodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function fingerprint(): DeclarationFingerprint
    {
        return $this->fingerprint;
    }

    /**
     * Null when the SIRET was not tracked when the declaration was recorded.
     */
    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    /**
     * The record this one replaces after a re-entry; null for a first declaration of the period.
     */
    public function supersedes(): ?DeclarationRecordId
    {
        return $this->supersedes;
    }

    /**
     * The record that replaced this one; null while this record is the current one of the period.
     */
    public function supersededBy(): ?DeclarationRecordId
    {
        return $this->supersededBy;
    }

    public function isCurrent(): bool
    {
        return !$this->supersededBy instanceof DeclarationRecordId;
    }

    public function createdAt(): Instant
    {
        return $this->createdAt;
    }

    public function status(): DeclarationStatus
    {
        return $this->status;
    }

    public function state(): DeclarationState
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
     * Null until declared.
     */
    public function result(): ?DeclarationResult
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
     * True when URSSAF accepted a call on a record that had already been superseded: the order in which URSSAF applied
     * the two declarations is unknown, so the record is counted under `needingAttention()` (ADR 0009).
     */
    public function isAcceptedAfterSupersession(): bool
    {
        return $this->acceptedAfterSupersession;
    }

    /**
     * Idempotency: true when the fingerprint is the one of the recorded declaration.
     */
    public function hasSameFingerprintAs(DeclarationFingerprint $fingerprint): bool
    {
        return $this->fingerprint->equals($fingerprint);
    }

    /**
     * pending -> submitting: one more attempt, started now. Concurrent workers should rather use
     * {@see DeclarationRecordRepository::claimForSubmission()}.
     *
     * @throws InvalidStateTransition
     */
    public function startSubmission(Instant $now): void
    {
        $this->status = $this->status->submissionStarted($now);
    }

    /**
     * submitting -> declared (050 succeeded). Clears the last errors.
     *
     * An accepted answer is never discarded: it is also recorded on a record that is no longer the claim's own, whatever
     * its state but declared (late acceptance, ADR 0009). Same event. On a superseded record the acceptance is flagged
     * ({@see self::isAcceptedAfterSupersession()}).
     *
     * @throws InvalidStateTransition from declared (the first result is kept)
     */
    public function markDeclared(DeclarationResult $result, Instant $now): void
    {
        $this->status = $this->status->declared($result, $now);
        if (!$this->isCurrent()) {
            $this->acceptedAfterSupersession = true;
        }
        $this->events[] = $this->declaredEvent($result, $now);
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
        $this->events[] = new DeclarationRejected(
            $this->id,
            $this->tenantId,
            $this->accountId,
            $this->period(),
            self::codes($errors),
            $now,
        );
    }

    /**
     * submitting -> outcome_unknown (transport error or 5xx on 050, or a stale submission): the request may have reached
     * URSSAF, so it is never resent automatically (ADR 0003).
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function markOutcomeUnknown(OutcomeUnknownReason $reason, array $errors, Instant $now): void
    {
        $this->status = $this->status->outcomeUnknown($reason, $errors, $now);
        $this->events[] = new DeclarationOutcomeUnknown(
            $this->id,
            $this->tenantId,
            $this->accountId,
            $this->period(),
            $reason,
            $now,
        );
    }

    /**
     * submitting -> error: a retryable failure, proven not sent (429, local rate limit) or, when 050 is listed in
     * `retry_safe_writes`, possibly sent (ADR 0003, ADR 0009).
     *
     * @param list<SubmissionError> $errors
     *
     * @throws InvalidStateTransition
     */
    public function markFailed(array $errors, Instant $now): void
    {
        $this->status = $this->status->failed($errors, $now);
        $this->events[] = new DeclarationSubmissionFailed($this->id, $this->tenantId, self::codes($errors), $now);
    }

    /**
     * submitting -> invalid_credentials. Clears the last errors.
     *
     * @throws InvalidStateTransition
     */
    public function markInvalidCredentials(Instant $now): void
    {
        $this->status = $this->status->credentialsRejected($now);
        $this->events[] = new DeclarationCredentialsRejected($this->id, $this->tenantId, $now);
    }

    /**
     * error -> pending (while attempts remain) or invalid_credentials -> pending (always). A record with an unknown
     * outcome is not retried: see {@see self::resolveAsNotReceived()}.
     *
     * @param positive-int $maxAttempts
     *
     * @throws InvariantViolation     "declaration_record.max_attempts_reached"
     * @throws InvalidStateTransition
     */
    public function retry(Instant $now, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): void
    {
        if (!$this->canRetry($maxAttempts)) {
            throw InvariantViolation::because(
                'declaration_record.max_attempts_reached',
                'The maximum number of submission attempts is reached.',
                ['attempts' => $this->status->attempts(), 'max_attempts' => $maxAttempts],
            );
        }
        $from = $this->status->state();
        $this->status = $this->status->retried($now);
        $this->events[] = new DeclarationRequeued($this->id, $this->tenantId, $from, $now);
    }

    /**
     * False only for a failed submission (state error) that used all its attempts.
     *
     * @param positive-int $maxAttempts
     */
    public function canRetry(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): bool
    {
        return $this->status->state() !== DeclarationState::Error || $this->status->hasAttemptsLeft($maxAttempts);
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
     * outcome_unknown -> declared: the operator found the declaration at URSSAF and read its `certificat` (nothing else
     * is known: no due date, no amounts).
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsDeclared(Certificate $certificate, Instant $now): void
    {
        $result = DeclarationResult::resolved($certificate);
        $this->status = $this->status->resolvedAsDeclared($result, $now);
        $this->events[] = $this->declaredEvent($result, $now);
    }

    /**
     * outcome_unknown -> pending: the operator checked that URSSAF did not receive the declaration.
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsNotReceived(Instant $now): void
    {
        $from = $this->status->state();
        $this->status = $this->status->resolvedAsNotReceived($now);
        $this->events[] = new DeclarationRequeued($this->id, $this->tenantId, $from, $now);
    }

    /**
     * True when the record is submitting and its last attempt started at or before now - staleAfter.
     */
    public function isStaleSubmission(Instant $now, DateInterval $staleAfter): bool
    {
        return $this->status->state() === DeclarationState::Submitting
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
        DeclarationRecordId $id,
        TenantId $tenantId,
        Siret $siret,
        DeclarationPeriod $period,
        DeclarationFingerprint $fingerprint,
        ?AccountId $accountId,
        ?DeclarationRecordId $supersedes,
        Instant $now,
    ): self {
        $record = new self(
            $id,
            $tenantId,
            $siret,
            $period->periodicity(),
            $period->year(),
            DeclarationPeriods::numberOf($period),
            $fingerprint,
            $accountId,
            $supersedes,
            $now,
            null,
            false,
        );
        $record->events[] = new DeclarationRecorded($id, $tenantId, $accountId, $period, $supersedes, $now);

        return $record;
    }

    private function declaredEvent(DeclarationResult $result, Instant $now): DeclarationDeclared
    {
        return new DeclarationDeclared(
            $this->id,
            $this->tenantId,
            $this->accountId,
            $this->period(),
            $result->certificate(),
            $result->declarationId(),
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
