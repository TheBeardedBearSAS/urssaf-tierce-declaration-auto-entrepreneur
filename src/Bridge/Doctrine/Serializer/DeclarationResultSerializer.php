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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Explicit JSON shape of a {@see DeclarationResult}: the certificate, and the declaration identifier, the due date and
 * the amounts of an accepted 050 (all three null for an operator resolution, which knows the certificate alone).
 *
 * The certificate is rebuilt with {@see Certificate::fromResponse()}: it is stored as URSSAF answered it, which need not
 * match the strict 8 digits of an operator input (ADR 0009 amendment). Personal data (ADR 0010).
 *
 * @internal
 *
 * @phpstan-import-type AmountsData from ContributionAmountsSerializer
 *
 * @phpstan-type ResultData array{certificate: string, declarationId: string|null, dueDate: string|null, amounts: AmountsData|null}
 */
final class DeclarationResultSerializer
{
    /**
     * @return ResultData
     */
    public static function toArray(DeclarationResult $result): array
    {
        $amounts = $result->amounts();

        return [
            'certificate' => $result->certificate()->value(),
            'declarationId' => $result->declarationId(),
            'dueDate' => $result->dueDate()?->toString(),
            'amounts' => $amounts instanceof ContributionAmounts ? ContributionAmountsSerializer::toArray($amounts) : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $data the document, of shape ResultData
     *
     * @throws InvalidValue
     */
    public static function fromArray(array $data): DeclarationResult
    {
        /** @var ResultData $data */
        $certificate = Certificate::fromResponse($data['certificate']);
        $declarationId = $data['declarationId'];
        $dueDate = $data['dueDate'];
        $amounts = $data['amounts'];

        if ($declarationId === null && $dueDate === null && $amounts === null) {
            return DeclarationResult::resolved($certificate);
        }
        if ($declarationId === null || $dueDate === null || $amounts === null) {
            throw InvalidValue::because('declaration_result.inconsistent', 'Invalid declaration_result: the stored document is inconsistent.');
        }

        return DeclarationResult::of($certificate, $declarationId, CalendarDate::fromString($dueDate), ContributionAmountsSerializer::fromArray($amounts));
    }
}
