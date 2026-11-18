<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * The `periode` object of 040 / 050 / 060 (ADR 0004, ADR 0006): `annee` plus `mois` (monthly) or `trimestre`
 * (quarterly). Written as integers, per contract; read as an integer or a digit string, as the PDF examples send
 * strings.
 */
final class PeriodEncoding
{
    /**
     * @return array{annee: int, mois: int}|array{annee: int, trimestre: int}
     */
    public static function encode(DeclarationPeriod $period): array
    {
        if ($period->periodicity() === Periodicity::Monthly) {
            return ['annee' => $period->year(), 'mois' => DeclarationPeriods::numberOf($period)];
        }

        return ['annee' => $period->year(), 'trimestre' => DeclarationPeriods::numberOf($period)];
    }

    /**
     * @throws UnexpectedUrssafResponse when `annee` is missing, when both or neither of `mois` and `trimestre` are
     *                                  present, or when a value is out of range
     */
    public static function decode(Payload $periode): DeclarationPeriod
    {
        $year = $periode->requireIntOrDigits('annee');
        $month = $periode->intOrDigits('mois');
        $quarter = $periode->intOrDigits('trimestre');

        if ($month !== null && $quarter !== null) {
            throw UnexpectedUrssafResponse::invalidPayload('field "periode" has both mois and trimestre');
        }

        if ($month !== null) {
            return self::build(Periodicity::Monthly, $year, $month);
        }

        if ($quarter !== null) {
            return self::build(Periodicity::Quarterly, $year, $quarter);
        }

        throw UnexpectedUrssafResponse::invalidPayload('field "periode" has neither mois nor trimestre');
    }

    private static function build(Periodicity $periodicity, int $year, int $number): DeclarationPeriod
    {
        try {
            return DeclarationPeriods::fromParts($periodicity, $year, $number);
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy('field "periode" is out of range', $exception);
        }
    }
}
