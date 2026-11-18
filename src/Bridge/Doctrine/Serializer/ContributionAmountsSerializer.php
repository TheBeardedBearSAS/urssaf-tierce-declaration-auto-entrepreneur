<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Serializer;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

/**
 * Explicit JSON shape of the amounts URSSAF answered to an accepted declaration (a part of the result document): whole
 * euros as integers, an absent amount as null, never as 0 (ADR 0006, ADR 0007). Declaration amounts are personal data
 * (ADR 0010).
 *
 * @internal
 *
 * @phpstan-type LiberatoryData array{sales: int|null, furnishedRental: int|null, commercialServices: int|null, otherServices: int|null, cipav: int|null}
 * @phpstan-type ChamberData array{cmaSales: int|null, cmaServices: int|null, cciSales: int|null, cciServices: int|null}
 * @phpstan-type AmountsData array{total: int, sales: int|null, furnishedRental: int|null, commercialServices: int|null, otherServices: int|null, cipav: int|null, vocationalTraining: int|null, liberatory: LiberatoryData, chamberFees: ChamberData, acreRegularisation: int|null, deduction: int|null}
 */
final class ContributionAmountsSerializer
{
    /**
     * @return AmountsData
     */
    public static function toArray(ContributionAmounts $amounts): array
    {
        $liberatory = $amounts->liberatory();
        $chamberFees = $amounts->chamberFees();

        return [
            'total' => $amounts->total()->toInt(),
            'sales' => self::euros($amounts->sales()),
            'furnishedRental' => self::euros($amounts->furnishedRental()),
            'commercialServices' => self::euros($amounts->commercialServices()),
            'otherServices' => self::euros($amounts->otherServices()),
            'cipav' => self::euros($amounts->cipav()),
            'vocationalTraining' => self::euros($amounts->vocationalTraining()),
            'liberatory' => [
                'sales' => self::euros($liberatory->sales()),
                'furnishedRental' => self::euros($liberatory->furnishedRental()),
                'commercialServices' => self::euros($liberatory->commercialServices()),
                'otherServices' => self::euros($liberatory->otherServices()),
                'cipav' => self::euros($liberatory->cipav()),
            ],
            'chamberFees' => [
                'cmaSales' => self::euros($chamberFees->cmaSales()),
                'cmaServices' => self::euros($chamberFees->cmaServices()),
                'cciSales' => self::euros($chamberFees->cciSales()),
                'cciServices' => self::euros($chamberFees->cciServices()),
            ],
            'acreRegularisation' => self::euros($amounts->acreRegularisation()),
            'deduction' => self::euros($amounts->deduction()),
        ];
    }

    /**
     * @param AmountsData $data
     *
     * @throws InvalidValue
     */
    public static function fromArray(array $data): ContributionAmounts
    {
        $liberatory = $data['liberatory'];
        $chamberFees = $data['chamberFees'];

        return ContributionAmounts::of(
            Euros::of($data['total']),
            self::amount($data['sales']),
            self::amount($data['furnishedRental']),
            self::amount($data['commercialServices']),
            self::amount($data['otherServices']),
            self::amount($data['cipav']),
            self::amount($data['vocationalTraining']),
            LiberatoryContributions::of(
                self::amount($liberatory['sales']),
                self::amount($liberatory['furnishedRental']),
                self::amount($liberatory['commercialServices']),
                self::amount($liberatory['otherServices']),
                self::amount($liberatory['cipav']),
            ),
            ChamberFees::of(
                self::amount($chamberFees['cmaSales']),
                self::amount($chamberFees['cmaServices']),
                self::amount($chamberFees['cciSales']),
                self::amount($chamberFees['cciServices']),
            ),
            self::amount($data['acreRegularisation']),
            self::amount($data['deduction']),
        );
    }

    private static function euros(?Euros $amount): ?int
    {
        return $amount?->toInt();
    }

    /**
     * @throws InvalidValue
     */
    private static function amount(?int $euros): ?Euros
    {
        return $euros === null ? null : Euros::of($euros);
    }
}
