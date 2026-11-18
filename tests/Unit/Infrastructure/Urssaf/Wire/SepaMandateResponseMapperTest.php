<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

/**
 * @mago-expect lint:too-many-methods
 */
final class SepaMandateResponseMapperTest extends TestCase
{
    private const RUM = 'T1234567891234567891234568912345678';

    private static function assertOfficialMandate(?SepaMandate $mandate): void
    {
        static::assertNotNull($mandate);
        static::assertSame(self::RUM, $mandate->rum()?->value());
        static::assertSame('string', $mandate->bankName());
        static::assertSame('FR0012345123451234567891A12', $mandate->iban());
        static::assertSame('Maxime LEFORESTIER', $mandate->accountHolder());
        static::assertSame('12345678912345', $mandate->siret()?->toString());

        $creditor = $mandate->creditor();
        static::assertNotNull($creditor);
        static::assertSame('URSSAF Bretagne', $creditor->name());
        static::assertSame('FR99000154431', $creditor->ics());
        static::assertSame('6 rue Robert d’Arbrissel', $creditor->firstLine());
        static::assertSame('', $creditor->secondLine());
        static::assertSame('35052', $creditor->postalCode());
        static::assertSame('FRANCE', $creditor->country());
        static::assertSame('Rennes cedex 9', $creditor->city());
    }

    public function testTheOfficialRegistrationAnswerIsMapped(): void
    {
        self::assertOfficialMandate(
            SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(Fixtures::load('sepa_mandate_response'))),
        );
    }

    public function testTheOfficialSearchAnswerIsMapped(): void
    {
        $mandates = SepaMandateResponseMapper::mapList(
            JsonDecoder::decode(Fixtures::load('sepa_mandates_search_response')),
        );

        static::assertCount(1, $mandates);
        self::assertOfficialMandate($mandates[0]);
    }

    public function testAnEmptyListGivesNoMandate(): void
    {
        static::assertSame([], SepaMandateResponseMapper::mapList(JsonDecoder::decode('[]')));
        static::assertSame([], SepaMandateResponseMapper::mapList(JsonDecoder::decode(" [ ]\n")));
    }

    public function testSeveralMandatesKeepTheirOrder(): void
    {
        $mandates = SepaMandateResponseMapper::mapList(JsonDecoder::decode('[{"rum":"B"},{},{"rum":"A"}]'));

        static::assertCount(3, $mandates);
        static::assertSame('B', $mandates[0]->rum()?->value());
        static::assertNull($mandates[1]->rum());
        static::assertSame('A', $mandates[2]->rum()?->value());
    }

    public function testAnEmptyEntryIsAMandateWithNothingKnown(): void
    {
        $mandates = SepaMandateResponseMapper::mapList(JsonDecoder::decode('[{},[]]'));

        static::assertCount(2, $mandates);
        static::assertNull($mandates[0]->rum());
        static::assertNull($mandates[1]->siret());
    }

    public function testAnEmptyObjectIsAMandateWithNothingKnown(): void
    {
        $mandate = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{}'));

        static::assertNull($mandate->rum());
        static::assertNull($mandate->bankName());
        static::assertNull($mandate->iban());
        static::assertNull($mandate->accountHolder());
        static::assertNull($mandate->siret());
        static::assertNull($mandate->creditor());
    }

    public function testNullFieldsAreAbsent(): void
    {
        $mandate = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(
            '{"rum":null,"nomBanque":null,"iban":null,"titulaireCompte":null,"siret":null,"creancier":null}',
        ));

        static::assertNull($mandate->rum());
        static::assertNull($mandate->bankName());
        static::assertNull($mandate->iban());
        static::assertNull($mandate->accountHolder());
        static::assertNull($mandate->siret());
        static::assertNull($mandate->creditor());
    }

    public function testACreditorWithNothingKnownHasNullFields(): void
    {
        $creditor = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{"creancier":{}}'))->creditor();

        static::assertNotNull($creditor);
        static::assertNull($creditor->name());
        static::assertNull($creditor->ics());
        static::assertNull($creditor->firstLine());
        static::assertNull($creditor->secondLine());
        static::assertNull($creditor->postalCode());
        static::assertNull($creditor->country());
        static::assertNull($creditor->city());
    }

    public function testEveryCreditorFieldIsReadFromItsOwnKey(): void
    {
        $creditor = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(
            '{"creancier":{"nom":"n","ics":"i","adresse1":"a1","adresse2":"a2","codePostal":"cp","pays":"p","ville":"v"}}',
        ))->creditor();

        static::assertNotNull($creditor);
        static::assertSame(['n', 'i', 'a1', 'a2', 'cp', 'p', 'v'], [
            $creditor->name(),
            $creditor->ics(),
            $creditor->firstLine(),
            $creditor->secondLine(),
            $creditor->postalCode(),
            $creditor->country(),
            $creditor->city(),
        ]);
    }

    public function testOnlyTheCityIsTrimmed(): void
    {
        $creditor = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(
            '{"creancier":{"nom":" n ","adresse2":" ","ville":" \tRennes \n"}}',
        ))->creditor();

        static::assertNotNull($creditor);
        static::assertSame(' n ', $creditor->name());
        static::assertSame(' ', $creditor->secondLine());
        static::assertSame('Rennes', $creditor->city());
    }

    public function testABlankCityIsNull(): void
    {
        foreach (['""', '"   "', 'null'] as $city) {
            $creditor = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{"creancier":{"ville":' . $city . '}}'))
                ->creditor();

            static::assertNotNull($creditor);
            static::assertNull($creditor->city());
        }
    }

    public function testTheBankDetailsAreKeptAsReceived(): void
    {
        $mandate = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(
            '{"iban":" FR76 **** ","nomBanque":"","titulaireCompte":"  x "}',
        ));

        static::assertSame(' FR76 **** ', $mandate->iban());
        static::assertSame('', $mandate->bankName());
        static::assertSame('  x ', $mandate->accountHolder());
    }

    public function testAnInvalidEchoedSiretOrRumIsNullOnARegistration(): void
    {
        $tooLongRum = str_repeat('R', 36);

        foreach (['"123"', '""', '" 12345678912345"', '"1234567891234x"', '"123456789123456"'] as $siret) {
            $mandate = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(
                '{"siret":' . $siret . ',"rum":"' . self::RUM . '"}',
            ));

            static::assertNull($mandate->siret());
            static::assertSame(self::RUM, $mandate->rum()?->value());
        }

        foreach (['""', '"   "', '"' . $tooLongRum . '"'] as $rum) {
            $mandate = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(
                '{"rum":' . $rum . ',"siret":"12345678912345"}',
            ));

            static::assertNull($mandate->rum());
            static::assertSame('12345678912345', $mandate->siret()?->toString());
        }
    }

    public function testTheBoundariesOfTheRumAreAccepted(): void
    {
        $longest = str_repeat('R', 35);

        $mandate = SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{"rum":"' . $longest . '"}'));

        static::assertSame($longest, $mandate->rum()?->value());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedListEntries(): iterable
    {
        yield 'invalid siret' => ['[{"siret":"123"}]', 'field "siret" is not a SIRET'];
        yield 'empty siret' => ['[{"siret":""}]', 'field "siret" is not a SIRET'];
        yield 'invalid siret in the second entry' => ['[{},{"siret":"x"}]', 'field "siret" is not a SIRET'];
        yield 'empty rum' => ['[{"rum":""}]', 'field "rum" is not a valid RUM'];
        yield 'blank rum' => ['[{"rum":"  "}]', 'field "rum" is not a valid RUM'];
        yield 'too long rum' => ['[{"rum":"R' . str_repeat('x', 35) . '"}]', 'field "rum" is not a valid RUM'];
        yield 'rum as an integer' => ['[{"rum":12}]', 'field "rum" is not a string'];
        yield 'siret as an integer' => ['[{"siret":12345678912345}]', 'field "siret" is not a string'];
        yield 'iban as an array' => ['[{"iban":["x"]}]', 'field "iban" is not a string'];
        yield 'bank name as a boolean' => ['[{"nomBanque":true}]', 'field "nomBanque" is not a string'];
        yield 'holder as a number' => ['[{"titulaireCompte":1.5}]', 'field "titulaireCompte" is not a string'];
        yield 'creditor as a string' => ['[{"creancier":"x"}]', 'field "creancier" is not an object'];
        yield 'creditor as a list' => ['[{"creancier":[{"nom":"x"}]}]', 'field "creancier" is not an object'];
        yield 'creditor name as an integer' => ['[{"creancier":{"nom":1}}]', 'field "nom" is not a string'];
        yield 'creditor city as an integer' => ['[{"creancier":{"ville":1}}]', 'field "ville" is not a string'];
        yield 'entry as a string' => ['["x"]', 'body is not a JSON array of objects'];
        yield 'entry as a null' => ['[null]', 'body is not a JSON array of objects'];
        yield 'entry as a number' => ['[1]', 'body is not a JSON array of objects'];
    }

    #[DataProvider('provideRefusedListEntries')]
    public function testAMalformedListIsRefused(string $json, string $reason): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: ' . $reason . '.',
            static fn(): array => SepaMandateResponseMapper::mapList(JsonDecoder::decode($json)),
        );
    }

    public function testTheListRefusesWhatIsNotAJsonArray(): void
    {
        foreach (['', "\n", '{}', '{"rum":"x"}'] as $body) {
            WireAssertions::assertUnexpected(
                'Unexpected URSSAF response: body is not a JSON array.',
                static fn(): array => SepaMandateResponseMapper::mapList(JsonDecoder::decode($body)),
            );
        }
    }

    public function testARegistrationRefusesWhatIsNotAnObject(): void
    {
        WireAssertions::assertRefusesWhatIsNotAnObject(SepaMandateResponseMapper::mapRegistered(...));
    }

    public function testARegistrationRefusesWrongTypesButNotInvalidEchoes(): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "rum" is not a string.',
            static fn(): SepaMandate => SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{"rum":1}')),
        );
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "siret" is not a string.',
            static fn(): SepaMandate => SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{"siret":1}')),
        );
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "creancier" is not an object.',
            static fn(): SepaMandate => SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode('{"creancier":[1]}')),
        );
    }

    public function testTheRefusalNeverHoldsTheValue(): void
    {
        $exception = WireAssertions::caught(
            static fn(): array => SepaMandateResponseMapper::mapList(JsonDecoder::decode('[{"siret":"S3CR3T-SIRET"}]')),
        );

        static::assertStringNotContainsString('S3CR3T', $exception->getMessage());
        $previous = $exception->getPrevious();
        static::assertNotNull($previous);
        static::assertStringNotContainsString('S3CR3T', $previous->getMessage());
    }
}
