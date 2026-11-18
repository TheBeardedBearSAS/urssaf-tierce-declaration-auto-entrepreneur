<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Closure;
use LogicException;
use Override;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use Throwable;

/**
 * Hand-written fake of the payment and SEPA gateway ports (060, 070, 071, 075): each operation answers what the test
 * queued, in order, and journals its call ("call:060"). A queued throwable is thrown; null is the void success of 071.
 * An unexpected call fails the test (an empty queue, not a queued null).
 */
final class FakePaymentGateway implements ContributionPayer, SepaMandateRegistrar, SepaMandateRevoker, SepaMandateFinder
{
    /** @var array<string, list<PaymentResult|SepaMandate|list<SepaMandate>|Throwable|null>> */
    private array $queues = [];

    /** @var list<string> */
    private array $calls = [];

    /** @var list<bool> */
    private array $ibansGiven = [];

    /** @var list<Siret> */
    private array $sirets = [];

    /** @var list<NewSepaMandate> */
    private array $registered = [];

    /** @var list<SepaRevocation> */
    private array $revoked = [];

    /** @var (Closure(): void)|null another process writing while the next call is in flight */
    private ?Closure $intrusion = null;

    public function __construct(
        private readonly Journal $journal,
    ) {}

    /**
     * Runs $intrusion once, during the next call (after it was journaled, before it answers): another process changing
     * the stored records while the HTTP call is in flight.
     *
     * @param Closure(): void $intrusion
     *
     * @param-immediately-invoked-callable $intrusion
     */
    public function duringNextCall(Closure $intrusion): void
    {
        $this->intrusion = $intrusion;
    }

    /**
     * @param PaymentResult|SepaMandate|list<SepaMandate>|Throwable|null ...$outcomes
     */
    public function queue(Operation $operation, PaymentResult|SepaMandate|array|Throwable|null ...$outcomes): void
    {
        $this->queues[$operation->value] = [...($this->queues[$operation->value] ?? []), ...array_values($outcomes)];
    }

    /**
     * @return list<string> the operations called, in order
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<bool> whether each 060 call had an IBAN (the IBAN itself is never kept)
     */
    public function ibansGiven(): array
    {
        return $this->ibansGiven;
    }

    /**
     * @return list<Siret> the SIRET of every call
     */
    public function sirets(): array
    {
        return $this->sirets;
    }

    /**
     * @return list<NewSepaMandate> the mandates sent to 070
     */
    public function registered(): array
    {
        return $this->registered;
    }

    /**
     * @return list<SepaRevocation> the revocations sent to 071
     */
    public function revoked(): array
    {
        return $this->revoked;
    }

    #[Override]
    public function payContributions(TenantId $tenantId, Siret $siret, #[SensitiveParameter] PaymentOrder $order): PaymentResult
    {
        $this->ibansGiven[] = $order->hasIban();
        $outcome = $this->answer(Operation::Pay, $siret);

        return $outcome instanceof PaymentResult ? $outcome : throw new LogicException('Queued another answer for 060.');
    }

    #[Override]
    public function registerSepaMandate(TenantId $tenantId, Siret $siret, #[SensitiveParameter] NewSepaMandate $mandate): SepaMandate
    {
        $this->registered[] = $mandate;
        $outcome = $this->answer(Operation::RegisterSepaMandate, $siret);

        return $outcome instanceof SepaMandate ? $outcome : throw new LogicException('Queued another answer for 070.');
    }

    #[Override]
    public function revokeSepaMandate(TenantId $tenantId, SepaRevocation $revocation): void
    {
        $this->revoked[] = $revocation;
        $outcome = $this->answer(Operation::RevokeSepaMandate, $revocation->siret());
        if ($outcome !== null) {
            throw new LogicException('Queued another answer for 071.');
        }
    }

    #[Override]
    public function listSepaMandates(TenantId $tenantId, Siret $siret): array
    {
        $outcome = $this->answer(Operation::ListSepaMandates, $siret);

        return \is_array($outcome) ? $outcome : throw new LogicException('Queued another answer for 075.');
    }

    /**
     * @return PaymentResult|SepaMandate|list<SepaMandate>|null
     */
    private function answer(Operation $operation, Siret $siret): PaymentResult|SepaMandate|array|null
    {
        $this->calls[] = $operation->value;
        $this->sirets[] = $siret;
        $this->journal->add('call:' . $operation->value);
        if ($this->intrusion instanceof Closure) {
            $intrusion = $this->intrusion;
            $this->intrusion = null;
            $intrusion();
        }
        if (($this->queues[$operation->value] ?? []) === []) {
            throw new LogicException('Unexpected call of ' . $operation->value . '.');
        }
        $outcome = array_shift($this->queues[$operation->value]);
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
