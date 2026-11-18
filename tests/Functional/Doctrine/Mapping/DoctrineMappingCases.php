<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Mapping;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;

/**
 * Data providers of {@see DoctrineMappingTest}: the case names understood by {@see DoctrineMappingSamples}.
 */
final class DoctrineMappingCases
{
    private function __construct() {}

    /**
     * @return iterable<string, array{string}> case names of {@see DoctrineMappingSamples::account()}
     */
    public static function accounts(): iterable
    {
        yield 'tracked, monthly, with its optional fields' => ['tracked'];
        yield 'tracked without birth date' => ['no-birth-date'];
        yield 'quarterly, without affiliation date nor ACRE answer' => ['quarterly-bare'];
        yield 'synced with a mandate' => ['synced'];
        yield 'birth date remembered later' => ['birth-remembered'];
    }

    /**
     * @return iterable<string, array{string}> case names of {@see DoctrineMappingSamples::mandate()}
     */
    public static function mandates(): iterable
    {
        yield 'notified' => ['notified'];
        yield 'imported' => ['imported'];
        yield 'cancelled' => ['cancelled'];
        yield 'cancellation recorded' => ['recorded'];
        yield 'without tracked account' => ['no-account'];
    }

    /**
     * @return iterable<string, array{string}> case names of {@see DoctrineMappingSamples::declarations()}
     */
    public static function declarations(): iterable
    {
        foreach (DeclarationState::cases() as $state) {
            yield 'state ' . $state->value => ['state:' . $state->value];
        }
        yield 'outcome unknown, stale submission' => ['unknown-stale'];
        yield 'quarterly period' => ['quarterly'];
        yield 'without tracked account' => ['no-account'];
        yield 'superseded by a new record' => ['superseded'];
        yield 'accepted after its supersession' => ['late-acceptance'];
        yield 'resolved by an operator' => ['resolved'];
    }

    /**
     * @return iterable<string, array{string}> case names of {@see DoctrineMappingSamples::payments()}
     */
    public static function payments(): iterable
    {
        foreach (PaymentState::cases() as $state) {
            yield 'state ' . $state->value => ['state:' . $state->value];
        }
        yield 'outcome unknown, stale submission' => ['unknown-stale'];
        yield 'quarterly period' => ['quarterly'];
        yield 'without SEPA mandate' => ['no-rum'];
        yield 'without tracked account' => ['no-account'];
        yield 'superseded by a new record' => ['superseded'];
        yield 'resolved by an operator' => ['resolved'];
    }
}
