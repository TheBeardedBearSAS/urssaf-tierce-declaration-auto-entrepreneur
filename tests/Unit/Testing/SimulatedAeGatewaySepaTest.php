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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;

/**
 * Methods 070, 071 and 075.
 *
 * @mago-expect lint:too-many-methods One test per rule of the simulator.
 */
final class SimulatedAeGatewaySepaTest extends TestCase
{
    public function testAMandateIsRegisteredWithANewRumAndNoCreditor(): void
    {
        $gateway = self::gateway();

        $mandate = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());

        static::assertSame('SIMRUM00000001', $mandate->rum()?->value());
        static::assertSame(SepaSamples::IBAN, $mandate->iban());
        static::assertSame(SepaSamples::HOLDER, $mandate->accountHolder());
        static::assertSame(SimulatorSamples::SIRET, $mandate->siret()?->toString());
        static::assertNull($mandate->creditor());
        static::assertNull($mandate->bankName());
    }

    public function testEachMandateGetsItsOwnRum(): void
    {
        $gateway = self::gateway();
        $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());

        $second = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), self::mandateWithIban('FR1420041010050500013M02606'));

        static::assertSame('SIMRUM00000002', $second->rum()?->value());
    }

    public function testTheSameIbanTwiceIsAConflict(): void
    {
        $gateway = self::gateway();
        $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());

        $refusal = SimulatorSamples::refusal(
            static fn(): SepaMandate => $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate()),
        );

        static::assertSame(409, $refusal->httpStatus());
        static::assertSame(RejectionKind::SepaMandateAlreadyExists, $refusal->kind());
        static::assertSame(['ERREUR_MANDAT_ALREADYEXIST'], $refusal->errors()->codes());
        static::assertCount(1, $gateway->listSepaMandates(SimulatorSamples::tenant(), SimulatorSamples::siret()));
    }

    public function testTheSameIbanOnAnotherSiretIsNotAConflict(): void
    {
        $gateway = self::gateway();
        $gateway->knowAccount(SimulatorSamples::otherSiret(), SimulatorSamples::birthDate());
        $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());

        $other = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::otherSiret(), SimulatorSamples::newMandate());

        static::assertSame('SIMRUM00000002', $other->rum()?->value());
    }

    public function testAnUnknownSiretIsANotFoundOnEverySepaOperation(): void
    {
        $gateway = SimulatorSamples::gateway();
        $operations = [Operation::RegisterSepaMandate, Operation::RevokeSepaMandate, Operation::ListSepaMandates];

        foreach ($operations as $operation) {
            $refusal = SimulatorSamples::refusal(static fn(): mixed => SimulatorSamples::call($gateway, $operation));

            static::assertSame(404, $refusal->httpStatus());
            static::assertSame(RejectionKind::NotFound, $refusal->kind());
            static::assertTrue($refusal->errors()->isEmpty());
        }
        static::assertSame(['070', '071', '075'], $gateway->calls());
    }

    public function testAMandateIsRevokedByItsRum(): void
    {
        $gateway = self::gateway();
        $first = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());
        $second = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), self::mandateWithIban('FR1420041010050500013M02606'));
        $rum = $first->rum();
        static::assertNotNull($rum);

        $gateway->revokeSepaMandate(SimulatorSamples::tenant(), SepaRevocation::of(SimulatorSamples::siret(), $rum, null));

        $remaining = $gateway->listSepaMandates(SimulatorSamples::tenant(), SimulatorSamples::siret());
        static::assertCount(1, $remaining);
        static::assertSame($second->rum()?->value(), $remaining[0]->rum()?->value());
    }

    public function testARevokedIbanCanBeRegisteredAgain(): void
    {
        $gateway = self::gateway();
        $first = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());
        $rum = $first->rum();
        static::assertNotNull($rum);
        $gateway->revokeSepaMandate(SimulatorSamples::tenant(), SepaRevocation::of(SimulatorSamples::siret(), $rum, null));

        $again = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());

        static::assertSame('SIMRUM00000002', $again->rum()?->value());
    }

    public function testAnUnknownRumIsANotFound(): void
    {
        $gateway = self::gateway();
        $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());

        $refusal = SimulatorSamples::refusal(
            static fn() => $gateway->revokeSepaMandate(SimulatorSamples::tenant(), SepaRevocation::of(SimulatorSamples::siret(), SimulatorSamples::rum('SIMRUM99999999'), null)),
        );

        static::assertSame(404, $refusal->httpStatus());
        static::assertSame(RejectionKind::NotFound, $refusal->kind());
        static::assertCount(1, $gateway->listSepaMandates(SimulatorSamples::tenant(), SimulatorSamples::siret()));
    }

    public function testARumOfAnotherSiretIsNotRevoked(): void
    {
        $gateway = self::gateway();
        $gateway->knowAccount(SimulatorSamples::otherSiret(), SimulatorSamples::birthDate());
        $mandate = $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate());
        $rum = $mandate->rum();
        static::assertNotNull($rum);

        $refusal = SimulatorSamples::refusal(
            static fn() => $gateway->revokeSepaMandate(SimulatorSamples::tenant(), SepaRevocation::of(SimulatorSamples::otherSiret(), $rum, null)),
        );

        static::assertSame(RejectionKind::NotFound, $refusal->kind());
        static::assertCount(1, $gateway->listSepaMandates(SimulatorSamples::tenant(), SimulatorSamples::siret()));
    }

    public function testAKnownSiretWithoutMandateListsNothing(): void
    {
        $gateway = self::gateway();

        static::assertSame([], $gateway->listSepaMandates(SimulatorSamples::tenant(), SimulatorSamples::siret()));
        static::assertSame(['075'], $gateway->calls());
    }

    private static function gateway(): SimulatedAeGateway
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());

        return $gateway;
    }

    private static function mandateWithIban(string $iban): NewSepaMandate
    {
        return NewSepaMandate::of(
            Bic::fromString(SepaSamples::BIC),
            Iban::forSepaMandate($iban),
            AccountHolder::fromString(SepaSamples::HOLDER),
            SepaSamples::address(),
        );
    }
}
