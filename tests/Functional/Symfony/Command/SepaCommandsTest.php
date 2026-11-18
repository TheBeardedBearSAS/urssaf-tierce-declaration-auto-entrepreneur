<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The SEPA mandate commands: register (070), revoke (071) and list (075).
 */
final class SepaCommandsTest extends TestCase
{
    private const RUM = 'T1234567891234567891234568912345678';

    public function testASepaMandateIsRegisteredAndOnlyItsRumAndMaskedIbanAreShown(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:register');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('sepa_mandate.json')], ['interactive' => false]));

        static::assertSame(['RUM' => self::RUM, 'IBAN' => 'FR00…1A12'], Fields::of($command->getDisplay()));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_070));
        $request = Requests::lastBody($harness);
        static::assertStringContainsString(SimulatorSamples::IBAN, $request);
        foreach ([SimulatorSamples::IBAN, 'ABCDEFGHIJK', 'LEFORESTIER', 'vauban', 'LILLE', 'URSSAF Bretagne', 'string'] as $personal) {
            static::assertStringNotContainsString($personal, $command->getDisplay());
        }
    }

    public function testASepaMandateNeedsItsPayload(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:register');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('The --payload option is required.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_070));
    }

    public function testAnInvalidSepaMandateIsRefusedWithoutItsContent(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:register');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('declaration.json')], ['interactive' => false]));

        static::assertStringContainsString('Invalid input: ', $command->getDisplay());
        static::assertStringContainsString('bic: ', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_070));
    }

    public function testAMandateIsRevokedWithoutAnEffectiveDate(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:revoke');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, 'rum' => self::RUM], ['interactive' => false]));

        static::assertSame("SEPA mandate revoked.\n", $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_071));
    }

    public function testAMandateIsRevokedWithAnEffectiveDateAfterToday(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:revoke');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, 'rum' => self::RUM, '--effective-on' => '2026-11-16'], ['interactive' => false]));

        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_071));
        static::assertStringContainsString('2026-11-16', Requests::lastUri($harness));
    }

    public function testAnEffectiveDateThatIsNotAfterTodayIsRefusedUnlessTheCheckIsSkipped(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:revoke');
        $arguments = ['siret' => Scenario::SIRET, 'rum' => self::RUM, '--effective-on' => '2026-11-15'];

        static::assertSame(2, $command->execute($arguments, ['interactive' => false]));
        static::assertStringContainsString('sepa_revocation.effective_date_too_early', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_071));

        static::assertSame(0, $command->execute([...$arguments, '--skip-effective-date-check' => true], ['interactive' => false]));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_071));
    }

    public function testAnInvalidRevocationIsRefusedBeforeAnyCall(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:revoke');

        static::assertSame(2, $command->execute(['siret' => '123', 'rum' => ' ', '--effective-on' => 'tomorrow'], ['interactive' => false]));

        static::assertStringContainsString('siret: ', $command->getDisplay());
        static::assertStringContainsString('rum: ', $command->getDisplay());
        static::assertStringContainsString('effective_date: ', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_071));
    }

    public function testTheMandatesAreListedWithTheirMaskedIban(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:sepa:list');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertSame([['RUM', 'IBAN'], [self::RUM, 'FR00…1A12']], Output::rows($command->getDisplay()));
        static::assertStringNotContainsString('LEFORESTIER', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_075));
    }

    public function testNoMandateGivesAnEmptyList(): void
    {
        $harness = Harness::boot();
        $harness->urssaf->respond(HttpScript::ROUTE_075, 200, '[]');
        $command = $harness->command('urssaf:tdae:sepa:list');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertSame([['RUM', 'IBAN']], Output::rows($command->getDisplay()));
    }

    public function testAnUrssafRefusalIsAFailure(): void
    {
        $harness = Harness::boot();
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_075, 400, 'error_param_invalide.json');
        $command = $harness->command('urssaf:tdae:sepa:list');

        static::assertSame(1, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));
    }
}
