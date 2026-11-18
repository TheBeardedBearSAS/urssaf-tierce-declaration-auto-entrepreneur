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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class PaymentResponseMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}> the wrapper key (null: direct) and the period encoding
     */
    public static function provideShapes(): iterable
    {
        yield 'direct, string periods (PDF)' => [null, 'string'];
        yield 'direct, integer periods (contract)' => [null, 'integer'];
        yield 'wrapped, string periods' => [ResponseEnvelope::PAYMENT, 'string'];
        yield 'wrapped, integer periods' => [ResponseEnvelope::PAYMENT, 'integer'];
    }

    #[DataProvider('provideShapes')]
    public function testTheOfficialAnswerIsMappedInEveryShape(?string $wrapperKey, string $periods): void
    {
        $json = $periods === 'integer' ? Fixtures::withIntegerPeriods('pay_response') : Fixtures::load('pay_response');
        if ($wrapperKey !== null) {
            $json = json_encode([$wrapperKey => Fixtures::decode($json)], JSON_THROW_ON_ERROR);
        }

        $result = PaymentResponseMapper::map(JsonDecoder::decode($json));

        static::assertSame('56335985', $result->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $result->paymentId());
        static::assertSame('2020-01-31', $result->dueDate()?->toString());
        static::assertSame('2019-12', $result->period()?->toString());
        static::assertFalse($result->isResolution());
    }

    public function testThePaymentIdentifierIsOptional(): void
    {
        $result = PaymentResponseMapper::map(JsonDecoder::decode(
            '{"periode":{"annee":2019,"mois":12},"dateExigibilite":"2020-01-31","certificat":"56335985"}',
        ));

        static::assertNull($result->paymentId());
        static::assertSame('56335985', $result->certificate()->value());
    }

    public function testANullPaymentIdentifierIsAbsent(): void
    {
        $result = PaymentResponseMapper::map(JsonDecoder::decode(
            '{"periode":{"annee":2019,"mois":12},"dateExigibilite":"2020-01-31","certificat":"56335985","idTelepaiement":null}',
        ));

        static::assertNull($result->paymentId());
    }

    public function testADeclarationWrapperDoesNotUnwrapAPayment(): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "periode" is missing or not an object.',
            static fn(): PaymentResult => PaymentResponseMapper::map(JsonDecoder::decode(
                '{"OutputDeclaration":{"periode":{"annee":2019,"mois":12},"dateExigibilite":"2020-01-31","certificat":"1"}}',
            )),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedAnswers(): iterable
    {
        $period = '"periode":{"annee":"2019","mois":"12"}';
        $due = '"dateExigibilite":"2020-01-31"';
        $certificate = '"certificat":"56335985"';

        yield 'missing period' => ['{' . $due . ',' . $certificate . '}', 'field "periode" is missing or not an object'];
        yield 'null period' => ['{"periode":null,' . $due . ',' . $certificate . '}', 'field "periode" is missing or not an object'];
        yield 'month out of range' => [
            '{"periode":{"annee":2019,"mois":13},' . $due . ',' . $certificate . '}',
            'field "periode" is out of range',
        ];
        yield 'period with both' => [
            '{"periode":{"annee":2019,"mois":1,"trimestre":1},' . $due . ',' . $certificate . '}',
            'field "periode" has both mois and trimestre',
        ];
        yield 'missing date' => ['{' . $period . ',' . $certificate . '}', 'field "dateExigibilite" is missing or empty'];
        yield 'date with a time part' => [
            '{' . $period . ',"dateExigibilite":"2020-01-31T00:00:00Z",' . $certificate . '}',
            'field "dateExigibilite" is not a Y-m-d date',
        ];
        yield 'missing certificate' => ['{' . $period . ',' . $due . '}', 'field "certificat" is missing or empty'];
        yield 'blank certificate' => ['{' . $period . ',' . $due . ',"certificat":" "}', 'field "certificat" is blank'];
        yield 'identifier as an integer' => [
            '{' . $period . ',' . $due . ',' . $certificate . ',"idTelepaiement":12}',
            'field "idTelepaiement" is not a string',
        ];
        yield 'wrapper null' => ['{"OutputTelepaiement":null}', 'field "OutputTelepaiement" is not a non-empty object'];
        yield 'wrapper empty' => ['{"OutputTelepaiement":{}}', 'field "OutputTelepaiement" is not a non-empty object'];
    }

    #[DataProvider('provideRefusedAnswers')]
    public function testAMalformedAnswerIsRefused(string $json, string $reason): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: ' . $reason . '.',
            static fn(): PaymentResult => PaymentResponseMapper::map(JsonDecoder::decode($json)),
        );
    }

    public function testWhatIsNotAnObjectIsRefused(): void
    {
        WireAssertions::assertRefusesWhatIsNotAnObject(PaymentResponseMapper::map(...));
    }
}
