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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountReferences;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class AccountReferencesTest extends TestCase
{
    public function testTheGivenBirthDateIsUsed(): void
    {
        $reference = AccountReferences::resolve(AccountWorld::siret(), AccountWorld::birthDate('1980-05-17'), null);

        static::assertSame(AccountWorld::SIRET, $reference->siret()->toString());
        static::assertSame('1980-05-17', $reference->birthDate()->toString());
    }

    public function testTheStoredBirthDateIsUsedWhenNoneIsGiven(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();

        $reference = AccountReferences::resolve(AccountWorld::siret(), null, $account);

        static::assertSame('1975-02-23', $reference->birthDate()->toString());
    }

    public function testTheGivenBirthDateWinsOverTheStoredOne(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();

        $reference = AccountReferences::resolve(AccountWorld::siret(), AccountWorld::birthDate('1980-05-17'), $account);

        static::assertSame('1980-05-17', $reference->birthDate()->toString());
    }

    public function testWithoutAnyBirthDateTheCallIsRefusedBeforeSending(): void
    {
        $world = new AccountWorld();
        $withoutBirthDate = $world->trackedAccountWithoutBirthDate();

        foreach ([null, $withoutBirthDate] as $account) {
            $failure = DomainAssertions::assertViolationCode(
                'validation.failed',
                static fn(): AccountReference => AccountReferences::resolve(AccountWorld::siret(), null, $account),
            );
            static::assertInstanceOf(ValidationFailed::class, $failure);
            static::assertSame(['error birthDate account_reference.birth_date_required'], DomainAssertions::summary($failure->result()));
            static::assertSame(
                'Methods 030 and 031 need the birth date: give it, or track the account with it.',
                $failure->result()->errors()[0]->message(),
            );
            static::assertSame([], $failure->result()->errors()[0]->params());
        }
    }

    public function testTheBirthDateParameterIsMasked(): void
    {
        DomainAssertions::assertSensitiveParameters(AccountReferences::class, 'resolve', ['given']);
    }
}
