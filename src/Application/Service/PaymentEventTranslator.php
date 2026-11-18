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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOrdered as DomainPaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOutcomeUnknown as DomainPaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRejected as DomainPaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

/**
 * Integration events of the payment workflow (ADR 0014). Recorded, requeued, technically failed and
 * rejected-credentials payments have none (item 4). The external reference is the payment reference; the account id is
 * null when the SIRET is not tracked.
 */
final class PaymentEventTranslator
{
    /**
     * @return list<IntegrationEvent> the integration event of the domain event, none when it has none
     */
    public static function translate(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof DomainPaymentOrdered => [self::ordered($event)],
            $event instanceof DomainPaymentRejected => [self::rejected($event)],
            $event instanceof DomainPaymentOutcomeUnknown => [self::outcomeUnknown($event)],
            default => [],
        };
    }

    private static function ordered(DomainPaymentOrdered $event): PaymentOrdered
    {
        $aggregateId = $event->recordId()->value();
        $tenantId = $event->tenantId()->value();
        $certificat = $event->certificate()->value();
        $idTelepaiement = $event->paymentId();
        $rum = $event->rum()?->value();
        [$periodicity, $year, $number] = IntegrationEventFacts::period($event->period());

        return new PaymentOrdered(
            IntegrationEventFacts::id(PaymentOrdered::TYPE, $tenantId, $aggregateId, $event->occurredAt(), [$certificat, $idTelepaiement, $rum]),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            $event->reference()->value(),
            $event->accountId()?->value(),
            $periodicity,
            $year,
            $number,
            $certificat,
            $idTelepaiement,
            $rum,
        );
    }

    private static function rejected(DomainPaymentRejected $event): PaymentRejected
    {
        $aggregateId = $event->recordId()->value();
        $tenantId = $event->tenantId()->value();
        $codes = $event->errorCodes();
        [$periodicity, $year, $number] = IntegrationEventFacts::period($event->period());

        return new PaymentRejected(
            IntegrationEventFacts::id(PaymentRejected::TYPE, $tenantId, $aggregateId, $event->occurredAt(), $codes),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            $event->reference()->value(),
            $event->accountId()?->value(),
            $periodicity,
            $year,
            $number,
            $codes,
        );
    }

    private static function outcomeUnknown(DomainPaymentOutcomeUnknown $event): PaymentOutcomeUnknown
    {
        $aggregateId = $event->recordId()->value();
        $tenantId = $event->tenantId()->value();
        $reason = $event->reason()->value;
        [$periodicity, $year, $number] = IntegrationEventFacts::period($event->period());

        return new PaymentOutcomeUnknown(
            IntegrationEventFacts::id(PaymentOutcomeUnknown::TYPE, $tenantId, $aggregateId, $event->occurredAt(), [$reason]),
            IntegrationEventFacts::instant($event->occurredAt()),
            $tenantId,
            $aggregateId,
            $event->reference()->value(),
            $event->accountId()?->value(),
            $periodicity,
            $year,
            $number,
            $reason,
        );
    }
}
