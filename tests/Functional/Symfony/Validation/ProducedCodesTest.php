<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Validation;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverDeclarationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Input\PaymentOrderFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Input\DeclarationPeriodFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\NewSepaMandateFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\SepaRevocationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * The other way round of {@see TranslationsTest}: the codes really produced by the input factories, fed with hostile
 * values, all have a message. It checks the scanner of {@see ViolationCodes} against the behaviour of the code.
 */
final class ProducedCodesTest extends TestCase
{
    public function testEveryCodeProducedByTheInputFactoriesIsTranslated(): void
    {
        $translated = array_keys(ViolationCodes::messages('en'));
        $produced = [];
        foreach (self::results() as $result) {
            foreach ($result->all() as $violation) {
                $produced[$violation->code()] = true;
            }
        }

        static::assertGreaterThan(20, \count($produced), 'The hostile inputs must reach many rules.');
        static::assertSame([], array_values(array_diff(array_keys($produced), $translated)));
    }

    /**
     * @return iterable<ValidationResult>
     */
    private static function results(): iterable
    {
        // The keys of the documented payloads, independently of the scanner (a key read under a variable name is
        // invisible to it).
        $documented = ['sales', 'furnished_rental', 'commercial_services', 'other_services', 'cipav_receipts', 'period', 'turnover', 'year', 'month', 'quarter', 'amount', 'iban', 'rum', 'siret', 'bic', 'account_holder', 'address', 'first_line', 'second_line', 'postal_code', 'city', 'country', 'effective_date'];
        $keys = array_values(array_unique([...$documented, ...array_map(
            static fn(string $code): string => substr($code, 0, (int) strrpos($code, '.')),
            array_filter(ViolationCodes::all(), static fn(string $code): bool => str_ends_with($code, '.invalid_type')),
        )]));
        $inputs = [[]];
        foreach (self::values() as $value) {
            $flat = array_fill_keys($keys, $value);
            $inputs[] = $flat;
            // Two levels of nesting and lists, for the nested maps and the items.
            $inputs[] = array_map(static fn(): array => $flat, $flat);
            $inputs[] = array_map(static fn(): array => [$flat, $flat], $flat);
        }
        foreach ($inputs as $input) {
            yield (new TurnoverFactory())->validate($input);
            yield (new DeclarationPeriodFactory())->validate($input);
            yield (new TurnoverDeclarationFactory())->validate($input);
            yield (new PaymentOrderFactory())->validate($input);
            yield (new SepaRevocationFactory())->validate($input);
            yield (new NewSepaMandateFactory())->validate($input);
        }
    }

    /**
     * @return list<array<array-key, mixed>|bool|int|string>
     */
    private static function values(): array
    {
        return [
            'ÿ ?!',
            str_repeat('A', 300),
            '0',
            '-1',
            '0.001',
            '99999999999999',
            '-0.5',
            '2026-02-31',
            'not a date',
            '12',
            '123456789012345',
            1,
            1_000_000,
            -5,
            true,
            [],
        ];
    }
}
