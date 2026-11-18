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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class EstimateResponseMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}> the wrapper key (null: direct) and the period encoding
     */
    public static function provideShapes(): iterable
    {
        yield 'direct, string periods (PDF)' => [null, 'string'];
        yield 'direct, integer periods (contract)' => [null, 'integer'];
        yield 'wrapped, string periods' => [ResponseEnvelope::ESTIMATE_OR_DECLARATION, 'string'];
        yield 'wrapped, integer periods' => [ResponseEnvelope::ESTIMATE_OR_DECLARATION, 'integer'];
    }

    #[DataProvider('provideShapes')]
    public function testTheOfficialAnswerIsMappedInEveryShape(?string $wrapperKey, string $periods): void
    {
        $json = $periods === 'integer' ? Fixtures::withIntegerPeriods('estimate_response') : Fixtures::load('estimate_response');
        if ($wrapperKey !== null) {
            $json = json_encode([$wrapperKey => Fixtures::decode($json)], JSON_THROW_ON_ERROR);
        }

        $estimate = EstimateResponseMapper::map(JsonDecoder::decode($json));

        static::assertSame(1678, $estimate->amounts()->total()->toInt());
        static::assertSame(128, $estimate->amounts()->sales()?->toInt());
        static::assertSame(0, $estimate->amounts()->furnishedRental()?->toInt());
        static::assertSame(660, $estimate->amounts()->commercialServices()?->toInt());
        static::assertSame(880, $estimate->amounts()->otherServices()?->toInt());
        static::assertSame(10, $estimate->amounts()->vocationalTraining()?->toInt());
        static::assertSame(0, $estimate->amounts()->cipav()?->toInt());
        static::assertSame(0, $estimate->amounts()->chamberFees()->cciServices()?->toInt());
        static::assertTrue($estimate->period()->equals(QuarterlyPeriod::of(2019, 1)));
    }

    public function testAMonthlyPeriodIsMapped(): void
    {
        $estimate = EstimateResponseMapper::map(
            JsonDecoder::decode('{"montants":{"montantTotal":5},"periode":{"annee":2020,"mois":"7"}}'),
        );

        static::assertSame('2020-07', $estimate->period()->toString());
    }

    public function testTheWrapperTakesPrecedenceOverSiblingFields(): void
    {
        $estimate = EstimateResponseMapper::map(JsonDecoder::decode(
            '{"OutputDeclaration":{"montants":{"montantTotal":7},"periode":{"annee":2020,"trimestre":2}},"montants":{"montantTotal":99}}',
        ));

        static::assertSame(7, $estimate->amounts()->total()->toInt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedAnswers(): iterable
    {
        $period = '"periode":{"annee":2019,"trimestre":1}';
        $amounts = '"montants":{"montantTotal":1}';

        yield 'missing montants' => ['{' . $period . '}', 'field "montants" is missing or not an object'];
        yield 'null montants' => ['{"montants":null,' . $period . '}', 'field "montants" is missing or not an object'];
        yield 'montants list' => ['{"montants":[1],' . $period . '}', 'field "montants" is missing or not an object'];
        yield 'montants string' => ['{"montants":"x",' . $period . '}', 'field "montants" is missing or not an object'];
        yield 'missing periode' => ['{' . $amounts . '}', 'field "periode" is missing or not an object'];
        yield 'null periode' => ['{' . $amounts . ',"periode":null}', 'field "periode" is missing or not an object'];
        yield 'periode list' => ['{' . $amounts . ',"periode":[2019]}', 'field "periode" is missing or not an object'];
        yield 'periode without a month' => ['{' . $amounts . ',"periode":{"annee":2019}}', 'field "periode" has neither mois nor trimestre'];
        yield 'montants without total' => ['{"montants":{},' . $period . '}', 'field "montantTotal" is missing'];
        yield 'wrapper null' => ['{"OutputDeclaration":null}', 'field "OutputDeclaration" is not a non-empty object'];
        yield 'wrapper empty' => ['{"OutputDeclaration":{}}', 'field "OutputDeclaration" is not a non-empty object'];
        yield 'wrapper list' => ['{"OutputDeclaration":[{"montants":{"montantTotal":1}}]}', 'field "OutputDeclaration" is not a non-empty object'];
        yield 'wrapper scalar' => ['{"OutputDeclaration":"x"}', 'field "OutputDeclaration" is not a non-empty object'];
        yield 'wrapper of another operation' => [
            '{"OutputTelepaiement":{' . $amounts . ',' . $period . '}}',
            'field "montants" is missing or not an object',
        ];
    }

    #[DataProvider('provideRefusedAnswers')]
    public function testAMalformedAnswerIsRefused(string $json, string $reason): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: ' . $reason . '.',
            static fn(): Estimate => EstimateResponseMapper::map(JsonDecoder::decode($json)),
        );
    }

    public function testWhatIsNotAnObjectIsRefused(): void
    {
        WireAssertions::assertRefusesWhatIsNotAnObject(EstimateResponseMapper::map(...));
    }
}
