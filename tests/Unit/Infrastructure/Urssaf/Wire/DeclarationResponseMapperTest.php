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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

/**
 * @mago-expect lint:halstead
 */
final class DeclarationResponseMapperTest extends TestCase
{
    private const AMOUNTS = '"montants":{"montantTotal":1678}';

    /**
     * @return iterable<string, array{string|null}> the wrapper key, null for a direct answer
     */
    public static function provideShapes(): iterable
    {
        yield 'direct' => [null];
        yield 'wrapped' => [ResponseEnvelope::ESTIMATE_OR_DECLARATION];
    }

    #[DataProvider('provideShapes')]
    public function testTheOfficialAnswerIsMappedInEveryShape(?string $wrapperKey): void
    {
        $json = $wrapperKey === null
            ? Fixtures::load('declare_response')
            : Fixtures::wrapped('declare_response', $wrapperKey);

        $result = DeclarationResponseMapper::map(JsonDecoder::decode($json));

        static::assertSame('56335826', $result->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $result->declarationId());
        static::assertSame('2020-01-31', $result->dueDate()?->toString());
        static::assertFalse($result->isResolution());

        $amounts = $result->amounts();
        static::assertNotNull($amounts);
        static::assertSame(1678, $amounts->total()->toInt());
        static::assertSame(128, $amounts->sales()?->toInt());
        static::assertSame(660, $amounts->commercialServices()?->toInt());
        static::assertSame(880, $amounts->otherServices()?->toInt());
        static::assertSame(10, $amounts->vocationalTraining()?->toInt());
    }

    public function testTheCertificateAndTheIdentifierAreKeptAsReceived(): void
    {
        $result = DeclarationResponseMapper::map(JsonDecoder::decode(
            '{' . self::AMOUNTS . ',"dateExigibilite":"2020-01-31","certificat":" 12 ab ","idDeclaration":"x"}',
        ));

        static::assertSame(' 12 ab ', $result->certificate()->value());
        static::assertSame('x', $result->declarationId());
    }

    public function testAnIdentifierLongerThanTheDocumentedOneIsKept(): void
    {
        $id = str_repeat('a', 200);

        $result = DeclarationResponseMapper::map(JsonDecoder::decode(
            '{' . self::AMOUNTS . ',"dateExigibilite":"2020-01-31","certificat":"1","idDeclaration":"' . $id . '"}',
        ));

        static::assertSame($id, $result->declarationId());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedAnswers(): iterable
    {
        $amounts = self::AMOUNTS;
        $due = '"dateExigibilite":"2020-01-31"';
        $certificate = '"certificat":"56335826"';
        $id = '"idDeclaration":"abc"';

        yield 'date with a time part' => [
            '{' . $amounts . ',"dateExigibilite":"2020-01-31T00:00:00Z",' . $certificate . ',' . $id . '}',
            'field "dateExigibilite" is not a Y-m-d date',
        ];
        yield 'impossible date' => [
            '{' . $amounts . ',"dateExigibilite":"2020-02-30",' . $certificate . ',' . $id . '}',
            'field "dateExigibilite" is not a Y-m-d date',
        ];
        yield 'french date' => [
            '{' . $amounts . ',"dateExigibilite":"31/01/2020",' . $certificate . ',' . $id . '}',
            'field "dateExigibilite" is not a Y-m-d date',
        ];
        yield 'missing date' => ['{' . $amounts . ',' . $certificate . ',' . $id . '}', 'field "dateExigibilite" is missing or empty'];
        yield 'null date' => [
            '{' . $amounts . ',"dateExigibilite":null,' . $certificate . ',' . $id . '}',
            'field "dateExigibilite" is missing or empty',
        ];
        yield 'missing certificate' => ['{' . $amounts . ',' . $due . ',' . $id . '}', 'field "certificat" is missing or empty'];
        yield 'empty certificate' => [
            '{' . $amounts . ',' . $due . ',"certificat":"",' . $id . '}',
            'field "certificat" is missing or empty',
        ];
        yield 'blank certificate' => [
            '{' . $amounts . ',' . $due . ',"certificat":"  ",' . $id . '}',
            'field "certificat" is blank',
        ];
        yield 'numeric certificate' => [
            '{' . $amounts . ',' . $due . ',"certificat":56335826,' . $id . '}',
            'field "certificat" is missing or empty',
        ];
        yield 'missing identifier' => ['{' . $amounts . ',' . $due . ',' . $certificate . '}', 'field "idDeclaration" is missing or empty'];
        yield 'empty identifier' => [
            '{' . $amounts . ',' . $due . ',' . $certificate . ',"idDeclaration":""}',
            'field "idDeclaration" is missing or empty',
        ];
        yield 'missing amounts' => ['{' . $due . ',' . $certificate . ',' . $id . '}', 'field "montants" is missing or not an object'];
        yield 'fractional total' => [
            '{"montants":{"montantTotal":1678.5},' . $due . ',' . $certificate . ',' . $id . '}',
            'field "montantTotal" is not a whole euro amount',
        ];
        yield 'total as a string' => [
            '{"montants":{"montantTotal":"1678"},' . $due . ',' . $certificate . ',' . $id . '}',
            'field "montantTotal" is not a number',
        ];
        yield 'optional amount as a string' => [
            '{"montants":{"montantTotal":1,"montantVente":"128"},' . $due . ',' . $certificate . ',' . $id . '}',
            'field "montantVente" is not a number',
        ];
        yield 'wrapper null' => ['{"OutputDeclaration":null}', 'field "OutputDeclaration" is not a non-empty object'];
    }

    #[DataProvider('provideRefusedAnswers')]
    public function testAMalformedAnswerIsRefused(string $json, string $reason): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: ' . $reason . '.',
            static fn(): DeclarationResult => DeclarationResponseMapper::map(JsonDecoder::decode($json)),
        );
    }

    public function testWhatIsNotAnObjectIsRefused(): void
    {
        WireAssertions::assertRefusesWhatIsNotAnObject(DeclarationResponseMapper::map(...));
    }
}
