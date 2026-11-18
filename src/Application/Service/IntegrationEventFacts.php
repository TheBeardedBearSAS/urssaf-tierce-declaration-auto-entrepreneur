<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Common values of the integration events: the event id is derived from the fact itself (the same fact always gets the
 * same id, so that the host can deduplicate), the instant is ISO 8601 UTC (ADR 0014).
 */
final class IntegrationEventFacts
{
    /**
     * The id covers the tenant: two tenants that record the same fact at the same instant get two ids.
     *
     * @param list<?string> $facts what distinguishes the fact from the other facts of the same type and record
     */
    public static function id(string $type, string $tenantId, ?string $aggregateId, DateTimeImmutable $occurredAt, array $facts): string
    {
        $hash = hash('sha256', serialize([$type, $tenantId, $aggregateId, self::instant($occurredAt), $facts]));

        return \sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }

    public static function instant(DateTimeImmutable $occurredAt): string
    {
        return Instant::fromDateTime($occurredAt)->toIso8601();
    }

    /**
     * @return array{string, int, int} periodicity code, year, month or quarter
     */
    public static function period(DeclarationPeriod $period): array
    {
        return [$period->periodicity()->value, $period->year(), DeclarationPeriods::numberOf($period)];
    }
}
