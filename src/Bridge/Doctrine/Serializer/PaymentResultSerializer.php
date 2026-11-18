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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Explicit JSON shape of a {@see PaymentResult}: the certificate, and the payment identifier (optional), the due date and
 * the period of an accepted 060 (the date and the period are null, and so is the identifier, for an operator resolution,
 * which knows the certificate alone).
 *
 * The certificate is rebuilt with {@see Certificate::fromResponse()}, as for a declaration result (ADR 0009 amendment).
 *
 * @internal
 *
 * @phpstan-type PeriodData array{periodicity: string, year: int, number: int}
 * @phpstan-type ResultData array{certificate: string, paymentId: string|null, dueDate: string|null, period: PeriodData|null}
 */
final class PaymentResultSerializer
{
    /**
     * @return ResultData
     */
    public static function toArray(PaymentResult $result): array
    {
        $period = $result->period();

        return [
            'certificate' => $result->certificate()->value(),
            'paymentId' => $result->paymentId(),
            'dueDate' => $result->dueDate()?->toString(),
            'period' => $period instanceof DeclarationPeriod ? [
                'periodicity' => $period->periodicity()->value,
                'year' => $period->year(),
                'number' => DeclarationPeriods::numberOf($period),
            ] : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $data the document, of shape ResultData
     *
     * @throws InvalidValue
     */
    public static function fromArray(array $data): PaymentResult
    {
        /** @var ResultData $data */
        $certificate = Certificate::fromResponse($data['certificate']);
        $paymentId = $data['paymentId'];
        $dueDate = $data['dueDate'];
        $period = $data['period'];

        if ($dueDate === null && $period === null && $paymentId === null) {
            return PaymentResult::resolved($certificate);
        }
        if ($dueDate === null || $period === null) {
            throw self::inconsistent();
        }
        $periodicity = Periodicity::fromCode($period['periodicity']);
        if (!$periodicity instanceof Periodicity) {
            throw self::inconsistent();
        }

        return PaymentResult::of($certificate, $paymentId, CalendarDate::fromString($dueDate), DeclarationPeriods::fromParts($periodicity, $period['year'], $period['number']));
    }

    private static function inconsistent(): InvalidValue
    {
        return InvalidValue::because('payment_result.inconsistent', 'Invalid payment_result: the stored document is inconsistent.');
    }
}
