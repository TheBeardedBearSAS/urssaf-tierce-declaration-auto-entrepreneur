<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Aggregate root: local follow-up of an auto-entrepreneur account, per tenant and {@see Siret} (ADR 0008, 0012). It only
 * exists from a method 020 snapshot ({@see AccountStatus}): {@see self::track()} records the first one, {@see self::sync()}
 * every following one. A mandate is never changed by a snapshot (ADR 0009).
 *
 * The identity (id, tenant, SIRET, creation instant) is immutable; the birth date is learned later, when a use case gets
 * it from the host (ADR 0010). SIRET and birth date are stored but never leave the aggregate through its events (ADR 0014).
 * Every notable change records a domain event, see {@see self::releaseEvents()}.
 *
 * Not final only because Doctrine generates lazy-loading proxies by extending the mapped entities: final by
 * convention, never extend it. For the same reason the identifier is neither readonly nor private: a proxy is created
 * with its identifier, which Doctrine assigns again (another instance of the same value) when the proxy is loaded. It is
 * never reassigned otherwise.
 *
 * @final
 */
class AeAccount
{
    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * Persistence concurrency token (optimistic lock of the Doctrine bridge): read and written by the ORM through
     * reflection, never by the domain. It is not a domain concept and has no accessor.
     */
    // @mago-expect analysis:unused-property Doctrine optimistic-lock token, read and written by the ORM through reflection.
    private int $version = 1;

    private function __construct(
        protected AccountId $id,
        private readonly TenantId $tenantId,
        private readonly Siret $siret,
        #[SensitiveParameter]
        private ?CalendarDate $birthDate,
        private AccountStatus $status,
        private readonly Instant $createdAt,
        private Instant $lastSyncedAt,
        private Instant $updatedAt,
    ) {}

    /**
     * First method 020 snapshot of the SIRET. Records {@see AccountSynced} (previous values null).
     *
     * @mago-expect lint:excessive-parameter-list One parameter per part of the account identity and its first 020 snapshot.
     */
    public static function track(
        AccountId $id,
        TenantId $tenantId,
        Siret $siret,
        AccountStatus $status,
        #[SensitiveParameter]
        ?CalendarDate $birthDate,
        Instant $now,
    ): self {
        $account = new self($id, $tenantId, $siret, $birthDate, $status, $now, $now, $now);
        $account->events[] = new AccountSynced(
            $id,
            $tenantId,
            null,
            $status->mandatePresent(),
            null,
            $status->periodicity(),
            $now,
        );

        return $account;
    }

    public function id(): AccountId
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

    public function birthDate(): ?CalendarDate
    {
        return $this->birthDate;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function periodicity(): Periodicity
    {
        return $this->status->periodicity();
    }

    public function createdAt(): Instant
    {
        return $this->createdAt;
    }

    public function lastSyncedAt(): Instant
    {
        return $this->lastSyncedAt;
    }

    public function updatedAt(): Instant
    {
        return $this->updatedAt;
    }

    /**
     * SIRET and birth date for methods 030 and 031; null while the birth date is unknown.
     */
    public function reference(): ?AccountReference
    {
        if (!$this->birthDate instanceof CalendarDate) {
            return null;
        }

        return AccountReference::of($this->siret, $this->birthDate);
    }

    /**
     * Method 020 answered: the snapshot is stored and the synchronisation instant refreshed. Records
     * {@see AccountSynced} only when the mandate presence or the periodicity changed (ADR 0014).
     */
    public function sync(AccountStatus $status, Instant $now): void
    {
        $previous = $this->status;
        $this->status = $status;
        $this->lastSyncedAt = $now;
        $this->updatedAt = $now;
        if ($status->differsInTrackedFieldsFrom($previous)) {
            $this->events[] = new AccountSynced(
                $this->id,
                $this->tenantId,
                $previous->mandatePresent(),
                $status->mandatePresent(),
                $previous->periodicity(),
                $status->periodicity(),
                $now,
            );
        }
    }

    /**
     * A use case got the birth date from the host (ADR 0010 item 3). Nothing changes when it is already the stored one.
     */
    public function rememberBirthDate(#[SensitiveParameter] CalendarDate $birthDate, Instant $now): void
    {
        if ($this->birthDate instanceof CalendarDate && $this->birthDate->equals($birthDate)) {
            return;
        }

        $this->birthDate = $birthDate;
        $this->updatedAt = $now;
    }

    /**
     * GDPR erasure (ForgetAccount, ADR 0017): records {@see AccountForgotten} with the ids erased with the account.
     * The state is not changed: the caller deletes the account and its records in the same transaction, then releases
     * the event.
     *
     * @param list<MandateId>           $mandateIds
     * @param list<DeclarationRecordId> $declarationRecordIds
     * @param list<PaymentRecordId>     $paymentRecordIds
     */
    public function forget(Instant $now, array $mandateIds, array $declarationRecordIds, array $paymentRecordIds): void
    {
        $this->events[] = new AccountForgotten(
            $this->id,
            $this->tenantId,
            $mandateIds,
            $declarationRecordIds,
            $paymentRecordIds,
            $now,
        );
    }

    /**
     * Returns the events recorded since the last call, and forgets them.
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
     * @return array{id: string, tenantId: string, siret: string, birthDate: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id->value(),
            'tenantId' => $this->tenantId->value(),
            'siret' => $this->siret->__debugInfo()['value'],
            'birthDate' => $this->birthDate instanceof CalendarDate ? '***' : null,
        ];
    }
}
