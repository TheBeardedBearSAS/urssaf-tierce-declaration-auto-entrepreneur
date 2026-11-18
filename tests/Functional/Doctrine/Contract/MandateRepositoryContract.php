<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateCancellationOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;

/**
 * Behaviour every {@see MandateRepository} must have, independently of the storage. Identity-related behaviour (copies
 * versus managed instances) is deliberately not part of the contract. One method per scenario.
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 * @mago-expect lint:too-many-methods
 */
trait MandateRepositoryContract
{
    protected MandateRepository $repository;

    abstract protected function createRepository(): MandateRepository;

    /**
     * An independent writer over the same store: another process, another EntityManager. The in-memory adapter has one
     * store per repository and returns the repository itself. Competing copies are read, and saved, through it.
     */
    abstract protected function anotherWriter(): MandateRepository;

    protected function setUp(): void
    {
        $this->repository = $this->createRepository();
    }

    /**
     * @param list<Mandate> $mandates
     *
     * @return list<string>
     */
    protected static function mandateIds(array $mandates): array
    {
        return array_map(static fn(Mandate $mandate): string => $mandate->id()->value(), $mandates);
    }

    public function testANotifiedMandateIsReadBack(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-10T09:00:00Z'));

        $read = $this->repository->get(MandateId::fromString('mandate-1'));

        static::assertSame('mandate-1', $read->id()->value());
        static::assertSame('tenant-1', $read->tenantId()->value());
        static::assertTrue($read->siret()->equals(ContractSamples::siret(1)));
        static::assertSame('account-1', $read->accountId()?->value());
        static::assertSame(MandateOrigin::Notified, $read->origin());
        static::assertSame(MandateState::Notified, $read->state());
        static::assertTrue($read->isActive());
        static::assertSame('2026-11-10T09:00:00Z', $read->notifiedAt()->toIso8601());
        static::assertNull($read->cancelledAt());
        static::assertNull($read->cancellationOrigin());
        static::assertSame('2026-11-10T09:00:00Z', $read->updatedAt()->toIso8601());
    }

    public function testAnImportedMandateWithoutAccountIsReadBack(): void
    {
        $mandate = Mandate::import(
            MandateId::fromString('mandate-1'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            null,
            ContractSamples::at(),
        );
        $this->repository->save($mandate);

        $read = $this->repository->get($mandate->id());

        static::assertSame(MandateOrigin::Imported, $read->origin());
        static::assertNull($read->accountId());
    }

    public function testACancelledMandateIsReadBackWithItsTimelineAndItsCancellationOrigin(): void
    {
        $cancelled = ContractSamples::mandate(1, 1);
        $cancelled->cancel(ContractSamples::at('2026-11-13T12:00:00Z'));
        $recorded = ContractSamples::mandate(2, 2);
        $recorded->recordCancellation(ContractSamples::at('2026-11-14T12:00:00Z'));
        $this->repository->save($cancelled);
        $this->repository->save($recorded);

        $read = $this->repository->get($cancelled->id());

        static::assertSame(MandateState::Cancelled, $read->state());
        static::assertFalse($read->isActive());
        static::assertSame('2026-11-15T10:00:00Z', $read->notifiedAt()->toIso8601());
        static::assertSame('2026-11-13T12:00:00Z', $read->cancelledAt()?->toIso8601());
        static::assertSame('2026-11-13T12:00:00Z', $read->updatedAt()->toIso8601());
        static::assertSame(MandateCancellationOrigin::Cancelled, $read->cancellationOrigin());
        static::assertSame(MandateCancellationOrigin::Recorded, $this->repository->get($recorded->id())->cancellationOrigin());
    }

    public function testTheEventsAreNeitherPersistedNorConsumedFromTheCaller(): void
    {
        $mandate = ContractSamples::pendingMandate(1, 1);

        $this->repository->save($mandate);

        static::assertCount(1, $mandate->releaseEvents());
        static::assertSame([], $this->repository->get($mandate->id())->releaseEvents());
    }

    public function testAnUnknownMandateIsNotFound(): void
    {
        try {
            $this->repository->get(MandateId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $exception) {
            static::assertSame('mandate.not_found', $exception->violationCode());
            static::assertSame('missing', $exception->identifier());
        }
    }

    public function testASecondActiveMandateOfTheSiretIsRefused(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));

        try {
            $this->repository->save(ContractSamples::mandate(2, 1));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.duplicate_active', $exception->violationCode());
            static::assertSame(MandateRepository::DUPLICATE_ACTIVE, $exception->violationCode());
            // The SIRET is personal data (ADR 0010): never in an exception.
            static::assertSame([], $exception->params());
            static::assertStringNotContainsString(ContractSamples::siret(1)->toString(), $exception->getMessage());
        }
    }

    public function testANewActiveMandateAfterCancelledOnesIsAccepted(): void
    {
        $this->repository->save(ContractSamples::cancelledMandate(1, 1));
        $this->repository->save(ContractSamples::cancelledMandate(2, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-16T10:00:00Z'));
        $this->repository->save(ContractSamples::mandate(3, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-17T10:00:00Z'));

        static::assertSame('mandate-3', $this->repository->findActive(ContractSamples::tenant(), ContractSamples::siret(1))?->id()->value());
    }

    public function testTheActiveMandateOfAnotherTenantOrAnotherSiretDoesNotConflict(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $this->repository->save(ContractSamples::mandate(2, 1, ContractSamples::OTHER_TENANT));
        $this->repository->save(ContractSamples::mandate(3, 2));

        static::assertSame('mandate-2', $this->repository->findActive(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1))?->id()->value());
        static::assertSame('mandate-3', $this->repository->findActive(ContractSamples::tenant(), ContractSamples::siret(2))?->id()->value());
    }

    public function testAnActiveMandateCanBeSavedAgainThenCancelled(): void
    {
        $mandate = ContractSamples::mandate(1, 1);
        $this->repository->save($mandate);
        $this->repository->save($mandate);
        $mandate->cancel(ContractSamples::at('2026-11-16T10:00:00Z'));
        $this->repository->save($mandate);

        static::assertSame(MandateState::Cancelled, $this->repository->get($mandate->id())->state());
        static::assertSame(['mandate-1'], self::mandateIds($this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1))));
    }

    public function testFindActiveIgnoresCancelledMandatesAndOtherTenants(): void
    {
        $tenant = ContractSamples::tenant();
        $this->repository->save(ContractSamples::cancelledMandate(1, 1));
        $this->repository->save(ContractSamples::mandate(2, 1, ContractSamples::OTHER_TENANT));

        static::assertNull($this->repository->findActive($tenant, ContractSamples::siret(1)));
        static::assertNull($this->repository->findActive($tenant, ContractSamples::siret(2)));

        $this->repository->save(ContractSamples::mandate(3, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-16T10:00:00Z'));

        static::assertSame('mandate-3', $this->repository->findActive($tenant, ContractSamples::siret(1))?->id()->value());
    }

    public function testTheMandatesOfASiretAreOrderedByNotificationThenIdAndTenantScoped(): void
    {
        $this->repository->save(ContractSamples::cancelledMandate(1, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-12T09:00:00Z'));
        $this->repository->save(ContractSamples::cancelledMandate(3, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::cancelledMandate(2, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::mandate(4, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-14T09:00:00Z'));
        $this->repository->save(ContractSamples::mandate(5, 1, ContractSamples::OTHER_TENANT, MandateOrigin::Notified, '2026-11-11T09:00:00Z'));
        $this->repository->save(ContractSamples::mandate(6, 2, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-11T09:00:00Z'));
        $tenant = ContractSamples::tenant();

        static::assertSame(['mandate-2', 'mandate-3', 'mandate-1', 'mandate-4'], self::mandateIds($this->repository->findBySiret($tenant, ContractSamples::siret(1))));
        static::assertSame(['mandate-5'], self::mandateIds($this->repository->findBySiret(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1))));
        static::assertSame([], $this->repository->findBySiret($tenant, ContractSamples::siret(3)));
    }

    public function testADeletedMandateIsGoneAndDeletingAMissingOneIsANoOp(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $this->repository->get($id);

        $this->repository->delete($id);
        $this->repository->delete(MandateId::fromString('missing'));

        static::assertSame([], $this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1)));
        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testDeletingTheActiveMandateLetsTheSiretHoldANewActiveMandate(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));

        $this->repository->delete(MandateId::fromString('mandate-1'));
        $this->repository->save(ContractSamples::mandate(2, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-16T10:00:00Z'));

        static::assertSame('mandate-2', $this->repository->findActive(ContractSamples::tenant(), ContractSamples::siret(1))?->id()->value());
    }

    public function testDeletingAMandateKeepsTheOtherMandates(): void
    {
        $this->repository->save(ContractSamples::cancelledMandate(1, 1));
        $this->repository->save(ContractSamples::mandate(2, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-16T10:00:00Z'));
        $this->repository->save(ContractSamples::mandate(3, 2));
        $this->repository->save(ContractSamples::mandate(4, 1, ContractSamples::OTHER_TENANT));

        $this->repository->delete(MandateId::fromString('mandate-1'));

        static::assertSame(['mandate-2'], self::mandateIds($this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1))));
        static::assertSame(['mandate-3'], self::mandateIds($this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(2))));
        static::assertSame(['mandate-4'], self::mandateIds($this->repository->findBySiret(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1))));
        static::assertSame('mandate-2', $this->repository->findActive(ContractSamples::tenant(), ContractSamples::siret(1))?->id()->value());
    }

    public function testACopyChangedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $first = $this->repository->get($id);
        $second = $this->anotherWriter()->get($id);
        $first->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $second->cancel(ContractSamples::at('2026-11-17T09:00:00Z'));
        $this->repository->save($first);

        try {
            $this->anotherWriter()->save($second);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.concurrent_modification', $exception->violationCode());
            static::assertSame(MandateRepository::CONCURRENT_MODIFICATION, $exception->violationCode());
        }
        static::assertSame('2026-11-16T09:00:00Z', $this->repository->get($id)->cancelledAt()?->toIso8601());
    }

    public function testACopyReadAgainAfterTheOtherWriterCanBeSavedAndSavedAgain(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $first = $this->repository->get($id);
        $this->repository->save($first);

        $second = $this->anotherWriter()->get($id);
        $second->cancel(ContractSamples::at('2026-11-17T09:00:00Z'));
        $this->anotherWriter()->save($second);
        $this->anotherWriter()->save($second);

        static::assertSame(MandateState::Cancelled, $this->repository->get($id)->state());
    }

    public function testACopyErasedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $stale = $this->repository->get($id);
        $stale->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->delete($id);

        $this->assertSaveIsRefusedAsConcurrent($stale);
        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testACopyFoundByAQueryIsCheckedLikeACopyReadById(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $tenant = ContractSamples::tenant();
        $siret = ContractSamples::siret(1);
        $found = [
            $this->repository->findActive($tenant, $siret),
            $this->repository->findBySiret($tenant, $siret)[0] ?? null,
        ];
        $other = $this->anotherWriter()->get(MandateId::fromString('mandate-1'));
        $other->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->save($other);

        foreach ($found as $stale) {
            static::assertNotNull($stale);
            $this->assertSaveIsRefusedAsConcurrent($stale);
        }
    }

    private function assertSaveIsRefusedAsConcurrent(Mandate $stale): void
    {
        try {
            $this->repository->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.concurrent_modification', $exception->violationCode());
        }
    }
}
