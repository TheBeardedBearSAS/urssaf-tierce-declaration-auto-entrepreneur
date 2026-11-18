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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionEstimator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use Throwable;

/**
 * Hand-written fake of the contribution gateway ports (040, 050): each operation answers what the test queued (a result,
 * or a throwable that is thrown), in order, and journals its call ("call:050"). An unexpected call fails the test.
 */
final class FakeContributionGateway implements ContributionEstimator, TurnoverDeclarer
{
    /** @var array<string, list<Estimate|DeclarationResult|Throwable>> */
    private array $queues = [];

    /** @var list<string> */
    private array $calls = [];

    /** @var list<TurnoverDeclaration> */
    private array $declarations = [];

    /** @var list<Turnover> */
    private array $turnovers = [];

    /** @var list<Siret> */
    private array $sirets = [];

    /** @var (Closure(): void)|null another process writing while the next call is in flight */
    private ?Closure $intrusion = null;

    public function __construct(private readonly Journal $journal) {}

    /**
     * Runs $intrusion once, during the next call (after it was journaled, before it answers): another process changing
     * the stored records while the HTTP call is in flight.
     *
     * @param Closure(): void $intrusion
     * @param-immediately-invoked-callable $intrusion
     */
    public function duringNextCall(Closure $intrusion): void
    {
        $this->intrusion = $intrusion;
    }

    public function queue(Operation $operation, Estimate|DeclarationResult|Throwable ...$outcomes): void
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
     * @return list<TurnoverDeclaration> the declarations sent to 050
     */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /**
     * @return list<Turnover> the turnovers sent to 040
     */
    public function turnovers(): array
    {
        return $this->turnovers;
    }

    /**
     * @return list<Siret> the SIRET of every call
     */
    public function sirets(): array
    {
        return $this->sirets;
    }

    #[Override]
    public function estimateContributions(TenantId $tenantId, Siret $siret, Turnover $turnover): Estimate
    {
        $this->turnovers[] = $turnover;
        $outcome = $this->answer(Operation::Estimate, $siret);

        return $outcome instanceof Estimate ? $outcome : throw new LogicException('Queued a declaration result for 040.');
    }

    #[Override]
    public function declareTurnover(TenantId $tenantId, Siret $siret, TurnoverDeclaration $declaration): DeclarationResult
    {
        $this->declarations[] = $declaration;
        $outcome = $this->answer(Operation::Declare, $siret);

        return $outcome instanceof DeclarationResult ? $outcome : throw new LogicException('Queued an estimate for 050.');
    }

    private function answer(Operation $operation, Siret $siret): Estimate|DeclarationResult
    {
        $this->calls[] = $operation->value;
        $this->sirets[] = $siret;
        $this->journal->add('call:' . $operation->value);
        if ($this->intrusion instanceof Closure) {
            $intrusion = $this->intrusion;
            $this->intrusion = null;
            $intrusion();
        }
        $outcome = array_shift($this->queues[$operation->value]) ?? throw new LogicException('Unexpected call of ' . $operation->value . '.');
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
