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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class AccountResponseMapperTest extends TestCase
{
    public function testTheOfficialAnswerIsMapped(): void
    {
        $status = AccountResponseMapper::map(JsonDecoder::decode(Fixtures::load('account_response')));

        static::assertFalse($status->mandatePresent());
        static::assertSame(Periodicity::Quarterly, $status->periodicity());
        static::assertSame('2010-03-01', $status->affiliationDate()?->toString());
        static::assertFalse($status->acreExemption());
    }

    public function testEveryFieldIsReadFromItsOwnKey(): void
    {
        $status = AccountResponseMapper::map(JsonDecoder::decode(
            '{"mandatPresent":true,"periodicite":"M","date_etat":"31/12/2019","exoAccrePresent":true}',
        ));

        static::assertTrue($status->mandatePresent());
        static::assertSame(Periodicity::Monthly, $status->periodicity());
        static::assertSame('2019-12-31', $status->affiliationDate()?->toString());
        static::assertTrue($status->acreExemption());
    }

    public function testThePdfOnlyFieldsAreOptional(): void
    {
        $status = AccountResponseMapper::map(JsonDecoder::decode('{"mandatPresent":true,"periodicite":"T"}'));

        static::assertTrue($status->mandatePresent());
        static::assertNull($status->affiliationDate());
        static::assertNull($status->acreExemption());
    }

    public function testNullPdfOnlyFieldsAreAbsent(): void
    {
        $status = AccountResponseMapper::map(JsonDecoder::decode(
            '{"mandatPresent":false,"periodicite":"M","date_etat":null,"exoAccrePresent":null}',
        ));

        static::assertNull($status->affiliationDate());
        static::assertNull($status->acreExemption());
    }

    public function testUnknownFieldsAndTheNirAreIgnored(): void
    {
        $status = AccountResponseMapper::map(JsonDecoder::decode(
            '{"mandatPresent":false,"periodicite":"M","nir":"not read","extra":[1]}',
        ));

        static::assertSame(Periodicity::Monthly, $status->periodicity());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedAnswers(): iterable
    {
        yield 'periodicity X' => ['{"mandatPresent":true,"periodicite":"X"}', 'field "periodicite" is not M or T'];
        yield 'periodicity lower case' => ['{"mandatPresent":true,"periodicite":"m"}', 'field "periodicite" is not M or T'];
        yield 'periodicity padded' => ['{"mandatPresent":true,"periodicite":"M "}', 'field "periodicite" is not M or T'];
        yield 'periodicity missing' => ['{"mandatPresent":true}', 'field "periodicite" is missing or empty'];
        yield 'periodicity empty' => ['{"mandatPresent":true,"periodicite":""}', 'field "periodicite" is missing or empty'];
        yield 'periodicity integer' => ['{"mandatPresent":true,"periodicite":1}', 'field "periodicite" is missing or empty'];
        yield 'mandate missing' => ['{"periodicite":"M"}', 'field "mandatPresent" is missing or not a boolean'];
        yield 'mandate null' => ['{"mandatPresent":null,"periodicite":"M"}', 'field "mandatPresent" is missing or not a boolean'];
        yield 'mandate string' => ['{"mandatPresent":"true","periodicite":"M"}', 'field "mandatPresent" is missing or not a boolean'];
        yield 'mandate integer' => ['{"mandatPresent":1,"periodicite":"M"}', 'field "mandatPresent" is missing or not a boolean'];
        yield 'malformed date' => [
            '{"mandatPresent":true,"periodicite":"M","date_etat":"2010-03-01"}',
            'field "date_etat" is not a DD/MM/YYYY date',
        ];
        yield 'impossible date' => [
            '{"mandatPresent":true,"periodicite":"M","date_etat":"31/02/2010"}',
            'field "date_etat" is not a DD/MM/YYYY date',
        ];
        yield 'blank date' => [
            '{"mandatPresent":true,"periodicite":"M","date_etat":""}',
            'field "date_etat" is not a DD/MM/YYYY date',
        ];
        yield 'date integer' => [
            '{"mandatPresent":true,"periodicite":"M","date_etat":20100301}',
            'field "date_etat" is not a string',
        ];
        yield 'acre string' => [
            '{"mandatPresent":true,"periodicite":"M","exoAccrePresent":"yes"}',
            'field "exoAccrePresent" is not a boolean',
        ];
    }

    #[DataProvider('provideRefusedAnswers')]
    public function testAMalformedAnswerIsRefused(string $json, string $reason): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: ' . $reason . '.',
            static fn(): AccountStatus => AccountResponseMapper::map(JsonDecoder::decode($json)),
        );
    }

    public function testWhatIsNotAnObjectIsRefused(): void
    {
        WireAssertions::assertRefusesWhatIsNotAnObject(AccountResponseMapper::map(...));
    }
}
