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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use Throwable;

/**
 * Records a mandate transition that URSSAF already accepted (030, 031) or that the host observed (import, cancellation
 * seen on the AE space), without any further call (ADR 0009).
 *
 * The per-SIRET lock of the host does not cover the status refresh (SyncAccount(s)): it can change the account between
 * the read and the save. Each attempt therefore reads the account and the mandate again in a new unit of work and applies
 * the transition to what it reads; a conflict ("ae_account.concurrent_modification", "mandate.concurrent_modification",
 * "mandate.duplicate_active") starts the next attempt, at most {@see self::MAX_ATTEMPTS}. A transition another writer
 * already recorded is not applied again (idempotent). A birth date given with 030 or 031 is stored on the tracked
 * account in the same transaction, and never without a tracked account.
 *
 * Failures. After a call to URSSAF ({@see self::recordNotification()}, {@see self::recordCancellation()}) the transition
 * must not be lost: every failure that is not a conflict (a violation, a storage or outbox failure, a mandate erased
 * meanwhile) and the exhaustion of the attempts are thrown as {@see MandateRecordingFailed}, the last failure being its
 * parameter "last_code", so that the host has one signal and a call-free recovery. Only {@see MandateRecordingFailed} means
 * that URSSAF accepted 030 / 031. {@see self::recordImport()} and {@see self::recordObservedCancellation()} make no call:
 * after the attempts they rethrow the last failure as it is.
 *
 * The rollback of a failed attempt needs a transactional TransactionManager: with the default ImmediateTransactionManager
 * what was saved before the conflict stays saved, and the next attempt completes it.
 *
 * @mago-expect lint:too-many-methods Four public recordings, each with its own idempotent attempt and a shared retry loop.
 */
final readonly class MandateRecorder
{
    public const MAX_ATTEMPTS = 3;

    private const CONFLICTS = [
        AeAccountRepository::CONCURRENT_MODIFICATION,
        MandateRepository::CONCURRENT_MODIFICATION,
        MandateRepository::DUPLICATE_ACTIVE,
    ];

    public function __construct(
        private MandateRepository $mandates,
        private AeAccountRepository $accounts,
        private IdGenerator $ids,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * Method 030 was accepted: the SIRET holds an active mandate. A mandate another writer already recorded is kept.
     *
     * @throws MandateRecordingFailed every attempt met a conflict, or an attempt failed (its code is "last_code")
     */
    public function recordNotification(TenantId $tenantId, Siret $siret, #[SensitiveParameter] ?CalendarDate $birthDate): Mandate
    {
        return $this->attempts(
            Operation::NotifyMandate,
            fn(): Mandate => $this->unitOfWork->run(
                fn(RecordedEvents $events): Mandate => $this->activateOnce($tenantId, $siret, $birthDate, MandateOrigin::Notified, $events),
            ),
        );
    }

    /**
     * A mandate obtained outside the package is adopted (no call). Idempotent: an active mandate that is already recorded
     * is kept.
     *
     * @throws InvariantViolation the last conflict after {@see self::MAX_ATTEMPTS} attempts, or a violation that is not a conflict
     */
    public function recordImport(TenantId $tenantId, Siret $siret, #[SensitiveParameter] ?CalendarDate $birthDate): Mandate
    {
        return $this->attempts(
            null,
            fn(): Mandate => $this->unitOfWork->run(
                fn(RecordedEvents $events): Mandate => $this->activateOnce($tenantId, $siret, $birthDate, MandateOrigin::Imported, $events),
            ),
        );
    }

    /**
     * Method 031 was accepted: the mandate is cancelled with the origin "cancelled". A mandate another writer already
     * cancelled is kept.
     *
     * @throws MandateRecordingFailed every attempt met a conflict, or an attempt failed, the mandate erased meanwhile included
     */
    public function recordCancellation(
        TenantId $tenantId,
        Siret $siret,
        MandateId $mandateId,
        #[SensitiveParameter]
        ?CalendarDate $birthDate,
    ): Mandate {
        return $this->attempts(
            Operation::CancelMandate,
            fn(): Mandate => $this->unitOfWork->run(
                fn(RecordedEvents $events): Mandate => $this->cancelOnce($tenantId, $siret, $mandateId, $birthDate, $events),
            ),
        );
    }

    /**
     * A cancellation observed outside the package is recorded (no call), with the origin "recorded". Idempotent.
     *
     * @throws RecordNotFound     the mandate was erased meanwhile
     * @throws InvariantViolation the last conflict after {@see self::MAX_ATTEMPTS} attempts, or a violation that is not a conflict
     */
    public function recordObservedCancellation(MandateId $mandateId): Mandate
    {
        return $this->attempts(
            null,
            fn(): Mandate => $this->unitOfWork->run(fn(RecordedEvents $events): Mandate => $this->observeOnce($mandateId, $events)),
        );
    }

    private function activateOnce(
        TenantId $tenantId,
        Siret $siret,
        #[SensitiveParameter]
        ?CalendarDate $birthDate,
        MandateOrigin $origin,
        RecordedEvents $events,
    ): Mandate {
        $account = $this->accounts->findBySiret($tenantId, $siret);
        $now = Instant::fromDateTime($this->clock->now());
        $mandate = $this->mandates->findActive($tenantId, $siret);
        if (!$mandate instanceof Mandate) {
            $mandate = ($origin === MandateOrigin::Imported ? Mandate::import(...) : Mandate::notify(...))(
                MandateId::fromString($this->ids->generate()),
                $tenantId,
                $siret,
                $account?->id(),
                $now,
            );
            $this->saveMandate($mandate, $events);
        }
        $this->rememberBirthDate($account, $birthDate, $now, $events);

        return $mandate;
    }

    private function cancelOnce(
        TenantId $tenantId,
        Siret $siret,
        MandateId $mandateId,
        #[SensitiveParameter]
        ?CalendarDate $birthDate,
        RecordedEvents $events,
    ): Mandate {
        $account = $this->accounts->findBySiret($tenantId, $siret);
        $mandate = $this->mandates->get($mandateId);
        $now = Instant::fromDateTime($this->clock->now());
        if ($mandate->isActive()) {
            $mandate->cancel($now);
            $this->saveMandate($mandate, $events);
        }
        $this->rememberBirthDate($account, $birthDate, $now, $events);

        return $mandate;
    }

    private function observeOnce(MandateId $mandateId, RecordedEvents $events): Mandate
    {
        $mandate = $this->mandates->get($mandateId);
        if ($mandate->isActive()) {
            $mandate->recordCancellation(Instant::fromDateTime($this->clock->now()));
            $this->saveMandate($mandate, $events);
        }

        return $mandate;
    }

    private function saveMandate(Mandate $mandate, RecordedEvents $events): void
    {
        $this->mandates->save($mandate);
        $events->record(...$mandate->releaseEvents());
    }

    private function rememberBirthDate(
        ?AeAccount $account,
        #[SensitiveParameter]
        ?CalendarDate $birthDate,
        Instant $now,
        RecordedEvents $events,
    ): void {
        if (!$account instanceof AeAccount || !$birthDate instanceof CalendarDate) {
            return;
        }

        $account->rememberBirthDate($birthDate, $now);
        $this->accounts->save($account);
        $events->record(...$account->releaseEvents());
    }

    /**
     * @template T
     *
     * @param Closure(): T $attempt
     * @param-immediately-invoked-callable $attempt
     * @param Operation|null $operation the call URSSAF accepted, or null without a call (nothing is wrapped then)
     *
     * @return T
     *
     * @throws Throwable
     */
    private function attempts(?Operation $operation, Closure $attempt): mixed
    {
        $number = 0;
        while (true) {
            ++$number;
            try {
                return $attempt();
            } catch (Throwable $failure) {
                $code = $failure instanceof DomainException ? $failure->violationCode() : null;
                if ($code !== null && \in_array($code, self::CONFLICTS, true) && $number < self::MAX_ATTEMPTS) {
                    continue;
                }
                if (!$operation instanceof Operation) {
                    throw $failure;
                }

                throw self::recordingFailed($operation, $number, $failure, $code);
            }
        }
    }

    /**
     * The reference exceptions carry no previous exception (ADR 0000 amendment): the last failure is the parameter "last_code".
     */
    private static function recordingFailed(Operation $operation, int $attempts, Throwable $failure, ?string $code): MandateRecordingFailed
    {
        return MandateRecordingFailed::after($operation, $attempts, $code ?? $failure::class);
    }
}
