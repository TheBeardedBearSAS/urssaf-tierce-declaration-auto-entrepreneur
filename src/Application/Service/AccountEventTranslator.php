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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten as DomainAccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountSynced as DomainAccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

/**
 * Integration events of the account workflow (ADR 0014). Tracking an account has none: the catalogue starts with the
 * changes seen by method 020 and with the erasure.
 */
final class AccountEventTranslator
{
    /**
     * @return list<IntegrationEvent> the integration event of the domain event, none when it has none
     */
    public static function translate(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof DomainAccountSynced => [self::synced($event)],
            $event instanceof DomainAccountForgotten => [self::forgotten($event)],
            default => [],
        };
    }

    private static function synced(DomainAccountSynced $event): AccountSynced
    {
        $aggregateId = $event->accountId()->value();
        $tenantId = $event->tenantId()->value();
        $previousMandatePresent = $event->previousMandatePresent();
        $mandatePresent = $event->mandatePresent();
        $previousPeriodicity = $event->previousPeriodicity()?->value;
        $periodicity = $event->periodicity()->value;

        return new AccountSynced(
            IntegrationEventFacts::id(
                AccountSynced::TYPE,
                $tenantId,
                $aggregateId,
                $event->occurredAt(),
                [
                    // Booleans are encoded as "1" / "0": the facts are strings.
                    match ($previousMandatePresent) {
                        null => null,
                        true => '1',
                        false => '0',
                    },
                    $mandatePresent ? '1' : '0',
                    $previousPeriodicity,
                    $periodicity,
                ],
            ),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $previousMandatePresent,
            $mandatePresent,
            $previousPeriodicity,
            $periodicity,
        );
    }

    private static function forgotten(DomainAccountForgotten $event): AccountForgotten
    {
        $aggregateId = $event->accountId()?->value();
        $tenantId = $event->tenantId()->value();
        $mandateIds = array_map(static fn(MandateId $id): string => $id->value(), $event->mandateIds());
        $declarationIds = array_map(static fn(DeclarationRecordId $id): string => $id->value(), $event->declarationRecordIds());
        $paymentIds = array_map(static fn(PaymentRecordId $id): string => $id->value(), $event->paymentRecordIds());

        return new AccountForgotten(
            IntegrationEventFacts::id(
                AccountForgotten::TYPE,
                $tenantId,
                $aggregateId,
                $event->occurredAt(),
                [...$mandateIds, '/', ...$declarationIds, '/', ...$paymentIds],
            ),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            null,
            $mandateIds,
            $declarationIds,
            $paymentIds,
        );
    }
}
