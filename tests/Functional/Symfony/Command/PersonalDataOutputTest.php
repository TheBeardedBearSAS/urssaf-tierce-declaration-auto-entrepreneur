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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * Nothing personal reaches a console output (ADR 0010): no birth date, no IBAN (except masked), no BIC, holder or address,
 * no message nor description of URSSAF; the hidden prompts are never echoed.
 */
final class PersonalDataOutputTest extends TestCase
{
    private const PERSONAL = [
        SimulatorSamples::BIRTH_DATE,
        SimulatorSamples::IBAN,
        'FR0012345123451234567891A12',
        'ABCDEFGHIJK',
        'LEFORESTIER',
        'Maxime',
        '13 boulevard vauban',
        'LILLE',
        '59000',
        'Rennes',
        'Robert d’Arbrissel',
        'URSSAF Bretagne',
        'Un des paramètres de votre demande est invalide',
        'Le paramètre SIRET ne doit pas dépasser 14 caractères',
        'Mandat inconnu',
        'Pas de mandat connu',
        'Internal Server Error',
    ];

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $inputs
     */
    private static function display(Harness $harness, string $name, array $arguments, array $inputs = []): string
    {
        $command = $harness->command($name);
        if ($inputs !== []) {
            $command->setInputs($inputs);
        }
        $command->execute($arguments, $inputs === [] ? ['interactive' => false] : []);

        return $command->getDisplay();
    }

    private static function assertNothingPersonal(string $display): void
    {
        foreach (self::PERSONAL as $personal) {
            static::assertStringNotContainsString($personal, $display);
        }
    }

    public function testTheMandateCommandsPrintNothingPersonal(): void
    {
        $harness = Harness::boot();
        $displays = [
            self::display($harness, 'urssaf:tdae:mandate:notify', ['siret' => Scenario::SIRET], [SimulatorSamples::BIRTH_DATE]),
            self::display($harness, 'urssaf:tdae:account:sync', ['siret' => Scenario::SIRET, '--with-birth-date' => true], [SimulatorSamples::BIRTH_DATE]),
            self::display($harness, 'urssaf:tdae:mandate:cancel', ['siret' => Scenario::SIRET, '--force' => true]),
            self::display($harness, 'urssaf:tdae:mandate:import', ['siret' => Scenario::SIRET]),
            self::display($harness, 'urssaf:tdae:mandate:record-cancellation', ['siret' => Scenario::SIRET]),
        ];

        foreach ($displays as $display) {
            self::assertNothingPersonal($display);
        }
        static::assertStringContainsString('Birth date (YYYY-MM-DD): ', $displays[0]);
    }

    public function testTheRefusalsOfUrssafPrintTheirCodesOnly(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_030, 400, 'error_mdt_inconnu.json');
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_050, 400, 'error_param_invalide.json');
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_060, 400, 'error_param_invalide.json');
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_070, 400, 'error_sepa_generic.json');
        $harness->urssaf->respond(HttpScript::ROUTE_031, 500, '{"message":"Internal Server Error"}');

        $displays = [
            self::display($harness, 'urssaf:tdae:mandate:notify', ['siret' => Scenario::SIRET]),
            self::display($harness, 'urssaf:tdae:mandate:cancel', ['siret' => Scenario::SIRET, '--force' => true]),
            self::display($harness, 'urssaf:tdae:declare', ['siret' => Scenario::SIRET, '--payload' => Fields::payload('declaration.json'), '--skip-periodicity-check' => true]),
            self::display($harness, 'urssaf:tdae:pay', ['siret' => Scenario::SIRET, 'payment-reference' => Scenario::PAYMENT, '--payload' => Fields::payload('payment.json')]),
            self::display($harness, 'urssaf:tdae:sepa:register', ['siret' => Scenario::SIRET, '--payload' => Fields::payload('sepa_mandate.json')]),
        ];

        foreach ($displays as $display) {
            self::assertNothingPersonal($display);
        }
    }

    public function testThePaymentAndTheSepaCommandsPrintNothingPersonal(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $displays = [
            self::display($harness, 'urssaf:tdae:pay', ['siret' => Scenario::SIRET, 'payment-reference' => Scenario::PAYMENT, '--payload' => Fields::payload('payment.json'), '--with-iban' => true], [SimulatorSamples::IBAN]),
            self::display($harness, 'urssaf:tdae:sepa:register', ['siret' => Scenario::SIRET, '--payload' => Fields::payload('sepa_mandate.json')]),
            self::display($harness, 'urssaf:tdae:sepa:list', ['siret' => Scenario::SIRET]),
            self::display($harness, 'urssaf:tdae:sepa:revoke', ['siret' => Scenario::SIRET, 'rum' => 'T1234567891234567891234568912345678']),
            self::display($harness, 'urssaf:tdae:pay', ['siret' => Scenario::SIRET, 'payment-reference' => Scenario::PAYMENT, '--payload' => Fields::payload('payment_with_iban.json')]),
            self::display($harness, 'urssaf:tdae:account:forget', ['siret' => Scenario::SIRET, '--allow-active-mandate' => true, '--force' => true]),
            self::display($harness, 'urssaf:tdae:estimate', ['siret' => Scenario::SIRET, '--payload' => Fields::payload('turnover.json'), '--skip-mandate-check' => true]),
        ];

        foreach ($displays as $display) {
            self::assertNothingPersonal($display);
        }
    }
}
