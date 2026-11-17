<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Aggregate root: local follow-up of the mandate given to the platform by an auto-entrepreneur (methods 030, 031; ADR 0009).
 * Created with {@see self::notify()} (after a successful 030) or {@see self::import()} (mandate notified outside the
 * package, no URSSAF call). A new notification after a cancellation is a new mandate (the application decides).
 *
 * The account is nullable: an {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount}
 * only exists from a method 020 snapshot, so a mandate may be notified or imported for a SIRET that is not tracked
 * (ADR 0014 item 3). The SIRET never leaves the aggregate through its events (ADR 0010).
 *
 * The state machine is {@see MandateState}; invalid transitions throw {@see InvalidStateTransition}
 * ("mandate.invalid_state_transition"). Every state change records a domain event, see {@see self::releaseEvents()}.
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
class Mandate
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
        protected MandateId $id,
        private readonly TenantId $tenantId,
        private readonly Siret $siret,
        private readonly ?AccountId $accountId,
        private readonly MandateOrigin $origin,
        private MandateState $state,
        private MandateTimeline $timeline,
        private ?MandateCancellationOrigin $cancellationOrigin,
    ) {}

    /**
     * After a successful method 030: state "notified", origin "notified". Records {@see MandateNotified}.
     */
    public static function notify(MandateId $id, TenantId $tenantId, Siret $siret, ?AccountId $accountId, Instant $now): self
    {
        return self::start($id, $tenantId, $siret, $accountId, MandateOrigin::Notified, $now);
    }

    /**
     * Mandate notified outside the package, adopted without any URSSAF call: state "notified", origin "imported".
     * Records {@see MandateNotified}.
     */
    public static function import(MandateId $id, TenantId $tenantId, Siret $siret, ?AccountId $accountId, Instant $now): self
    {
        return self::start($id, $tenantId, $siret, $accountId, MandateOrigin::Imported, $now);
    }

    public function id(): MandateId
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

    /**
     * The tracked account of the SIRET when the mandate was created, null when the SIRET was not tracked (ADR 0014 item 3).
     */
    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    public function origin(): MandateOrigin
    {
        return $this->origin;
    }

    public function state(): MandateState
    {
        return $this->state;
    }

    public function timeline(): MandateTimeline
    {
        return $this->timeline;
    }

    public function notifiedAt(): Instant
    {
        return $this->timeline->notifiedAt();
    }

    public function cancelledAt(): ?Instant
    {
        return $this->timeline->cancelledAt();
    }

    public function updatedAt(): Instant
    {
        return $this->timeline->updatedAt();
    }

    /**
     * How the mandate was cancelled; null while it is not cancelled.
     */
    public function cancellationOrigin(): ?MandateCancellationOrigin
    {
        return $this->cancellationOrigin;
    }

    /**
     * Not cancelled (used by the mandate guard, ADR 0011 item 1).
     */
    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    /**
     * Method 031 succeeded: notified -> cancelled, cancellation origin "cancelled". Records {@see MandateCancelled}.
     *
     * @throws InvalidStateTransition "mandate.invalid_state_transition" when already cancelled
     */
    public function cancel(Instant $now): void
    {
        $this->cancelWith(MandateCancellationOrigin::Cancelled, $now);
    }

    /**
     * Cancellation made outside the package, recorded without any URSSAF call: notified -> cancelled, cancellation origin
     * "recorded". Records {@see MandateCancelled}.
     *
     * @throws InvalidStateTransition "mandate.invalid_state_transition" when already cancelled
     */
    public function recordCancellation(Instant $now): void
    {
        $this->cancelWith(MandateCancellationOrigin::Recorded, $now);
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
     * @mago-expect lint:excessive-parameter-list One parameter per part of the mandate identity.
     */
    private static function start(MandateId $id, TenantId $tenantId, Siret $siret, ?AccountId $accountId, MandateOrigin $origin, Instant $now): self
    {
        $mandate = new self($id, $tenantId, $siret, $accountId, $origin, MandateState::Notified, MandateTimeline::notified($now), null);
        $mandate->events[] = new MandateNotified($id, $tenantId, $accountId, $origin, $now);

        return $mandate;
    }

    private function cancelWith(MandateCancellationOrigin $origin, Instant $now): void
    {
        $this->state = $this->state->transitionTo(MandateState::Cancelled);
        $this->timeline = $this->timeline->withCancellation($now);
        $this->cancellationOrigin = $origin;
        $this->events[] = new MandateCancelled($this->id, $this->tenantId, $this->accountId, $origin, $now);
    }
}
