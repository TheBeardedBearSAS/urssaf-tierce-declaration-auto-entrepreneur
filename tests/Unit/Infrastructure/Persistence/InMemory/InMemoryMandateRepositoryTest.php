<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;

/**
 * @mago-expect lint:too-many-methods One test per rule of the repository.
 */
final class InMemoryMandateRepositoryTest extends TestCase
{
    private InMemoryMandateRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryMandateRepository();
    }

    public function testASavedMandateIsReadBackAsACopy(): void
    {
        $mandate = Records::mandate('m1');
        $this->repository->save($mandate);

        $read = $this->repository->get(MandateId::fromString('m1'));

        static::assertNotSame($mandate, $read);
        static::assertEquals($mandate, $read);
    }

    public function testAChangeIsOnlyVisibleOnceSaved(): void
    {
        $this->repository->save(Records::mandate('m1'));
        $read = $this->repository->get(MandateId::fromString('m1'));

        $read->cancel(Records::at(30));

        static::assertTrue($this->repository->get(MandateId::fromString('m1'))->isActive());
        $this->repository->save($read);
        static::assertFalse($this->repository->get(MandateId::fromString('m1'))->isActive());
    }

    public function testTheEventsAreNeitherPersistedNorConsumed(): void
    {
        $mandate = Mandate::notify(
            MandateId::fromString('m1'),
            Records::tenant(),
            Records::siret(),
            null,
            Records::at(),
        );

        $this->repository->save($mandate);

        static::assertCount(1, $mandate->releaseEvents());
        static::assertSame([], $this->repository->get(MandateId::fromString('m1'))->releaseEvents());
    }

    public function testAnUnknownMandateIsNotFound(): void
    {
        try {
            $this->repository->get(MandateId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('mandate.not_found', $notFound->violationCode());
            static::assertSame('missing', $notFound->identifier());
        }
    }

    public function testASecondActiveMandateOfTheSameTenantAndSiretIsRefused(): void
    {
        $this->repository->save(Records::mandate('m1'));

        try {
            $this->repository->save(Records::mandate('m2'));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(MandateRepository::DUPLICATE_ACTIVE, $violation->violationCode());
            static::assertSame([], $violation->params());
        }
        static::assertCount(1, $this->repository->all());
    }

    public function testAnotherTenantAnotherSiretOrACancelledMandateIsAccepted(): void
    {
        $this->repository->save(Records::mandate('m1'));
        $this->repository->save(Records::mandate('m2', Slot::new()->tenant('tenant-2')));
        $this->repository->save(Records::mandate('m3', Slot::new()->siret(2)));
        $this->repository->save(Records::cancelledMandate('m4', Slot::new()->minutes(5)));

        static::assertCount(4, $this->repository->all());
    }

    public function testACancelledMandateIsNotCheckedAgainstTheActiveOne(): void
    {
        $this->repository->save(Records::cancelledMandate('m1'));
        $this->repository->save(Records::mandate('m2', Slot::new()->minutes(5)));
        $cancelled = $this->repository->get(MandateId::fromString('m1'));

        $this->repository->save($cancelled);

        static::assertCount(2, $this->repository->all());
    }

    public function testAnActiveMandateStoredBeforeACancelledOneDoesNotBlockThemselfWhenResaved(): void
    {
        $mandate = Records::mandate('m1');
        $this->repository->save($mandate);
        $this->repository->save($mandate);

        static::assertCount(1, $this->repository->all());
    }

    public function testFindActiveIsScopedToTheTenantAndTheSiretAndIgnoresCancelledMandates(): void
    {
        $this->repository->save(Records::cancelledMandate('m0'));
        $this->repository->save(Records::mandate('m1', Slot::new()->minutes(1)));
        $this->repository->save(Records::mandate('m2', Slot::new()->tenant('tenant-2')->minutes(2)));
        $this->repository->save(Records::mandate('m3', Slot::new()->siret(2)->minutes(3)));
        $this->repository->save(Records::cancelledMandate('m4', Slot::new()->siret(3)->minutes(4)));

        static::assertSame('m1', $this->repository->findActive(Records::tenant('tenant-1'), Records::siret(1))?->id()->value());
        static::assertSame('m2', $this->repository->findActive(Records::tenant('tenant-2'), Records::siret(1))?->id()->value());
        static::assertSame('m3', $this->repository->findActive(Records::tenant('tenant-1'), Records::siret(2))?->id()->value());
        static::assertNull($this->repository->findActive(Records::tenant('tenant-1'), Records::siret(3)));
        static::assertNull($this->repository->findActive(Records::tenant('tenant-3'), Records::siret(1)));
    }

    public function testFindBySiretListsTheMandatesOldestNotificationFirstTiesById(): void
    {
        $this->repository->save(Records::cancelledMandate('m3', Slot::new()->minutes(5)));
        $this->repository->save(Records::cancelledMandate('m2'));
        $this->repository->save(Records::cancelledMandate('m1'));
        $this->repository->save(Records::mandate('m4', Slot::new()->minutes(9)));
        $this->repository->save(Records::mandate('other-siret', Slot::new()->siret(2)));
        $this->repository->save(Records::mandate('other-tenant', Slot::new()->tenant('tenant-2')));

        $found = $this->repository->findBySiret(Records::tenant(), Records::siret(1));

        static::assertSame(['m1', 'm2', 'm3', 'm4'], array_map(static fn(Mandate $m): string => $m->id()->value(), $found));
        static::assertSame([], $this->repository->findBySiret(Records::tenant('tenant-3'), Records::siret(1)));
        static::assertNotSame($found[0], $this->repository->findBySiret(Records::tenant(), Records::siret(1))[0]);
    }

    public function testDeleteFreesTheActiveKeyAndAMissingMandateIsIgnored(): void
    {
        $this->repository->save(Records::mandate('m1'));

        $this->repository->delete(MandateId::fromString('m1'));
        $this->repository->delete(MandateId::fromString('m1'));
        $this->repository->save(Records::mandate('m2'));

        static::assertSame(
            ['m2'],
            array_map(static fn(Mandate $mandate): string => $mandate->id()->value(), $this->repository->all()),
        );
    }

    public function testAStaleCopyAnErasedCopyAndAQueryCopyAreRefused(): void
    {
        $this->repository->save(Records::mandate('m1'));
        $first = $this->repository->get(MandateId::fromString('m1'));
        $second = $this->repository->get(MandateId::fromString('m1'));
        $queried = $this->repository->findActive(Records::tenant(), Records::siret(1));
        $listed = $this->repository->findBySiret(Records::tenant(), Records::siret(1))[0];
        $this->repository->save($first);
        $this->repository->save($first);

        $this->assertConcurrent($second);
        static::assertInstanceOf(Mandate::class, $queried);
        $this->assertConcurrent($queried);
        $this->assertConcurrent($listed);

        $this->repository->delete(MandateId::fromString('m1'));
        $this->assertConcurrent($first);
    }

    public function testANeverReadInstanceIsRefusedForAStoredIdentifier(): void
    {
        $this->repository->save(Records::mandate('m1'));

        $this->assertConcurrent(Records::mandate('m1', Slot::new()->siret(2)));
    }

    private function assertConcurrent(Mandate $mandate): void
    {
        try {
            $this->repository->save($mandate);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(MandateRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
    }
}
