<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;

/**
 * Methods 030 and 031.
 */
final class SimulatedAeGatewayMandateTest extends TestCase
{
    public function testTheMandateIsHeldByTheNotifyingTenant(): void
    {
        $gateway = self::gateway();

        $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference());

        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
        static::assertTrue($gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->mandatePresent());
        static::assertSame(['030', '020'], $gateway->calls());
    }

    public function testNotifyingAgainIsIdempotentForTheHolder(): void
    {
        $gateway = self::gateway();
        $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference());

        $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference());

        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
    }

    public function testAMandateHeldByAnotherTenantIsRefused(): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::otherTenant());

        $refusal = SimulatorSamples::refusal(
            static fn() => $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()),
        );

        static::assertSame(400, $refusal->httpStatus());
        static::assertSame(RejectionKind::MandateHeldElsewhere, $refusal->kind());
        static::assertSame(['SIRET_DEJA_MANDATE'], $refusal->errors()->codes());
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::otherTenant()));
        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
    }

    public function testADifferentBirthDateIsAnInvalidParameterOnBothOperations(): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $wrong = AccountReference::of(SimulatorSamples::siret(), CalendarDate::fromString('1980-01-01'));

        $notify = SimulatorSamples::refusal(static fn() => $gateway->notifyMandate(SimulatorSamples::tenant(), $wrong));
        $cancel = SimulatorSamples::refusal(static fn() => $gateway->cancelMandate(SimulatorSamples::tenant(), $wrong));

        foreach ([$notify, $cancel] as $refusal) {
            static::assertSame(400, $refusal->httpStatus());
            static::assertSame(RejectionKind::InvalidParameter, $refusal->kind());
            static::assertSame(['PARAM_INVALIDE'], $refusal->errors()->codes());
        }
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
    }

    public function testTheMandateIsReleasedByItsHolder(): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $gateway->cancelMandate(SimulatorSamples::tenant(), SimulatorSamples::reference());

        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
        static::assertFalse($gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->mandatePresent());
    }

    public function testCancellingWithoutAMandateIsRefusedWithTheDocumentedMessage(): void
    {
        $gateway = self::gateway();

        $refusal = SimulatorSamples::refusal(
            static fn() => $gateway->cancelMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()),
        );

        static::assertSame(RejectionKind::InvalidParameter, $refusal->kind());
        static::assertSame(['PARAM_INVALIDE'], $refusal->errors()->codes());
        static::assertSame(
            'Aucun mandat à supprimer correspondant à vos données d\'entrée',
            $refusal->errors()->first()?->description(),
        );
    }

    public function testCancellingAMandateHeldByAnotherTenantIsRefusedAndKeepsIt(): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::otherTenant());

        $refusal = SimulatorSamples::refusal(
            static fn() => $gateway->cancelMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()),
        );

        static::assertSame(['PARAM_INVALIDE'], $refusal->errors()->codes());
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::otherTenant()));
    }

    private static function gateway(): SimulatedAeGateway
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());

        return $gateway;
    }
}
