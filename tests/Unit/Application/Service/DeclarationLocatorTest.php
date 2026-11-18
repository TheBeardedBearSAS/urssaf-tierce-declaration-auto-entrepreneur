<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use Throwable;

final class DeclarationLocatorTest extends TestCase
{
    public function testItLocatesARecordById(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        $record = $world->locator->locate(DeclarationWorld::tenant(), DeclarationSelector::byRecordId(DeclarationRecordId::fromString('record-1')));

        static::assertSame('record-1', $record->id()->value());
    }

    public function testItLocatesTheCurrentRecordOfASiretAndAPeriod(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Rejected, 1);

        $record = $world->locator->locate(DeclarationWorld::tenant(), DeclarationSelector::current(DeclarationWorld::siret(), DeclarationWorld::period()));

        static::assertSame('record-1', $record->id()->value());
    }

    public function testARecordOfAnotherTenantIsNotFoundByIdAndTheErrorNamesTheId(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        $thrown = $this->locateAndCatch($world, DeclarationWorld::tenant('tenant-2'), DeclarationSelector::byRecordId(DeclarationRecordId::fromString('record-1')));

        static::assertInstanceOf(RecordNotFound::class, $thrown);
        static::assertSame('declaration_record.not_found', $thrown->violationCode());
        static::assertSame('record-1', $thrown->identifier());
    }

    public function testAnUnknownIdIsNotFound(): void
    {
        $thrown = $this->locateAndCatch(new DeclarationWorld(), DeclarationWorld::tenant(), DeclarationSelector::byRecordId(DeclarationRecordId::fromString('missing')));

        static::assertInstanceOf(RecordNotFound::class, $thrown);
        static::assertSame('missing', $thrown->identifier());
    }

    public function testASiretAndPeriodWithoutRecordAreNotFoundAndTheSiretIsNeverTheIdentifier(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        $otherPeriod = $this->locateAndCatch($world, DeclarationWorld::tenant(), DeclarationSelector::current(DeclarationWorld::siret(), DeclarationWorld::period(9)));
        $otherTenant = $this->locateAndCatch($world, DeclarationWorld::tenant('tenant-2'), DeclarationSelector::current(DeclarationWorld::siret(), DeclarationWorld::period()));

        foreach ([$otherPeriod, $otherTenant] as $thrown) {
            static::assertInstanceOf(RecordNotFound::class, $thrown);
            static::assertSame('***', $thrown->identifier());
            static::assertSame('declaration_record.not_found', $thrown->violationCode());
            static::assertStringNotContainsString(DeclarationWorld::SIRET, $thrown->getMessage());
        }
    }

    private function locateAndCatch(DeclarationWorld $world, TenantId $tenant, DeclarationSelector $selector): ?Throwable
    {
        try {
            $world->locator->locate($tenant, $selector);
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
