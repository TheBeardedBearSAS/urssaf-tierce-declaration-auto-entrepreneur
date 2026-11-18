<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Query;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetDeclarationHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;

final class GetDeclarationHandlerTest extends TestCase
{
    public function testItReturnsTheViewOfARecordById(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Declared, 1);

        $view = $this->handler($world)(new GetDeclaration(DeclarationWorld::tenant(), DeclarationSelector::byRecordId(DeclarationRecordId::fromString('record-1'))));

        static::assertSame('record-1', $view->recordId);
        static::assertSame('declared', $view->status->state);
        static::assertSame('12345678', $view->status->result?->certificat);
    }

    public function testItReturnsTheViewOfTheCurrentRecordOfASiretAndAPeriod(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        $view = $this->handler($world)(new GetDeclaration(
            DeclarationWorld::tenant(),
            DeclarationSelector::current(DeclarationWorld::siret(), DeclarationWorld::period()),
        ));

        static::assertSame('record-1', $view->recordId);
    }

    public function testAnUnknownRecordIsNotFound(): void
    {
        $this->expectException(RecordNotFound::class);
        $this->expectExceptionMessage('No declaration_record found for identifier "missing".');

        $this->handler(new DeclarationWorld())(new GetDeclaration(DeclarationWorld::tenant(), DeclarationSelector::byRecordId(DeclarationRecordId::fromString('missing'))));
    }

    public function testARecordOfAnotherTenantIsNotFound(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        $this->expectException(RecordNotFound::class);

        $this->handler($world)(new GetDeclaration(DeclarationWorld::tenant('tenant-2'), DeclarationSelector::byRecordId(DeclarationRecordId::fromString('record-1'))));
    }

    private function handler(DeclarationWorld $world): GetDeclarationHandler
    {
        return new GetDeclarationHandler($world->locator);
    }
}
