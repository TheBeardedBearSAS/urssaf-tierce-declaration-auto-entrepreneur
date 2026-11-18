<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Contract\Event;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRevoked;

/**
 * One sample of every integration event of the catalogue (ADR 0014) with its type and its exact properties.
 */
final class CatalogueSamples
{
    public const AT = '2026-11-15T10:00:00Z';

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    public static function all(): iterable
    {
        yield from self::accounts();
        yield from self::mandates();
        yield from self::declarations();
        yield from self::payments();
        yield from self::sepa();
    }

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    private static function accounts(): iterable
    {
        yield 'account synced' => [
            new AccountSynced('event-1', self::AT, 'tenant-1', 'account-1', null, false, true, 'T', 'M'),
            'urssaf.tdae.account.synced',
            [...self::common('account-1', null), 'previousMandatePresent' => false, 'mandatePresent' => true, 'previousPeriodicity' => 'T', 'periodicity' => 'M'],
        ];
        yield 'account synced on the first read' => [
            new AccountSynced('event-1', self::AT, 'tenant-1', 'account-1', null, null, true, null, 'M'),
            'urssaf.tdae.account.synced',
            [...self::common('account-1', null), 'previousMandatePresent' => null, 'mandatePresent' => true, 'previousPeriodicity' => null, 'periodicity' => 'M'],
        ];
        yield 'account forgotten' => [
            new AccountForgotten('event-1', self::AT, 'tenant-1', 'account-1', null, ['mandate-1', 'mandate-2'], ['declaration-1'], ['payment-1']),
            'urssaf.tdae.account.forgotten',
            [...self::common('account-1', null), 'mandateIds' => ['mandate-1', 'mandate-2'], 'declarationRecordIds' => ['declaration-1'], 'paymentRecordIds' => ['payment-1']],
        ];
        yield 'account forgotten without a tracked account' => [
            new AccountForgotten('event-1', self::AT, 'tenant-1', null, null, [], [], []),
            'urssaf.tdae.account.forgotten',
            [...self::common(null, null), 'mandateIds' => [], 'declarationRecordIds' => [], 'paymentRecordIds' => []],
        ];
    }

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    private static function mandates(): iterable
    {
        yield 'mandate notified' => [
            new MandateNotified('event-1', self::AT, 'tenant-1', 'mandate-1', null, 'account-1', 'imported'),
            'urssaf.tdae.mandate.notified',
            [...self::common('mandate-1', null), 'accountId' => 'account-1', 'origin' => 'imported'],
        ];
        yield 'mandate cancelled' => [
            new MandateCancelled('event-1', self::AT, 'tenant-1', 'mandate-1', null, null, 'recorded'),
            'urssaf.tdae.mandate.cancelled',
            [...self::common('mandate-1', null), 'accountId' => null, 'origin' => 'recorded'],
        ];
    }

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    private static function declarations(): iterable
    {
        $period = ['periodicity' => 'M', 'periodYear' => 2026, 'periodNumber' => 10];

        yield 'declaration declared' => [
            new DeclarationDeclared('event-1', self::AT, 'tenant-1', 'declaration-1', null, 'account-1', 'M', 2026, 10, '12345678', 'id-1'),
            'urssaf.tdae.declaration.declared',
            [...self::common('declaration-1', null), 'accountId' => 'account-1', ...$period, 'certificat' => '12345678', 'idDeclaration' => 'id-1'],
        ];
        yield 'declaration declared by an operator' => [
            new DeclarationDeclared('event-1', self::AT, 'tenant-1', 'declaration-1', null, null, 'T', 2026, 3, '12345678', null),
            'urssaf.tdae.declaration.declared',
            [...self::common('declaration-1', null), 'accountId' => null, 'periodicity' => 'T', 'periodYear' => 2026, 'periodNumber' => 3, 'certificat' => '12345678', 'idDeclaration' => null],
        ];
        yield 'declaration rejected' => [
            new DeclarationRejected('event-1', self::AT, 'tenant-1', 'declaration-1', null, 'account-1', 'M', 2026, 10, ['ERR_A', 'ERR_B']),
            'urssaf.tdae.declaration.rejected',
            [...self::common('declaration-1', null), 'accountId' => 'account-1', ...$period, 'errorCodes' => ['ERR_A', 'ERR_B']],
        ];
        yield 'declaration outcome unknown' => [
            new DeclarationOutcomeUnknown('event-1', self::AT, 'tenant-1', 'declaration-1', null, 'account-1', 'M', 2026, 10, 'stale_submission'),
            'urssaf.tdae.declaration.outcome_unknown',
            [...self::common('declaration-1', null), 'accountId' => 'account-1', ...$period, 'reason' => 'stale_submission'],
        ];
    }

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    private static function payments(): iterable
    {
        $period = ['periodicity' => 'M', 'periodYear' => 2026, 'periodNumber' => 10];

        yield 'payment ordered' => [
            new PaymentOrdered('event-1', self::AT, 'tenant-1', 'payment-1', 'payment-ref-1', 'account-1', 'M', 2026, 10, '12345678', 'pay-1', 'RUM-1'),
            'urssaf.tdae.payment.ordered',
            [...self::common('payment-1', 'payment-ref-1'), 'accountId' => 'account-1', ...$period, 'certificat' => '12345678', 'idTelepaiement' => 'pay-1', 'rum' => 'RUM-1'],
        ];
        yield 'payment ordered without id or RUM' => [
            new PaymentOrdered('event-1', self::AT, 'tenant-1', 'payment-1', 'payment-ref-1', null, 'M', 2026, 10, '12345678', null, null),
            'urssaf.tdae.payment.ordered',
            [...self::common('payment-1', 'payment-ref-1'), 'accountId' => null, ...$period, 'certificat' => '12345678', 'idTelepaiement' => null, 'rum' => null],
        ];
        yield 'payment rejected' => [
            new PaymentRejected('event-1', self::AT, 'tenant-1', 'payment-1', 'payment-ref-1', 'account-1', 'M', 2026, 10, ['ERR_A']),
            'urssaf.tdae.payment.rejected',
            [...self::common('payment-1', 'payment-ref-1'), 'accountId' => 'account-1', ...$period, 'errorCodes' => ['ERR_A']],
        ];
        yield 'payment outcome unknown' => [
            new PaymentOutcomeUnknown('event-1', self::AT, 'tenant-1', 'payment-1', 'payment-ref-1', 'account-1', 'M', 2026, 10, 'may_have_reached_urssaf'),
            'urssaf.tdae.payment.outcome_unknown',
            [...self::common('payment-1', 'payment-ref-1'), 'accountId' => 'account-1', ...$period, 'reason' => 'may_have_reached_urssaf'],
        ];
    }

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    private static function sepa(): iterable
    {
        yield 'sepa mandate registered' => [
            new SepaMandateRegistered('event-1', self::AT, 'tenant-1', null, null, 'RUM-1'),
            'urssaf.tdae.sepa_mandate.registered',
            [...self::common(null, null), 'rum' => 'RUM-1'],
        ];
        yield 'sepa mandate revoked' => [
            new SepaMandateRevoked('event-1', self::AT, 'tenant-1', null, null, 'RUM-1'),
            'urssaf.tdae.sepa_mandate.revoked',
            [...self::common(null, null), 'rum' => 'RUM-1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function common(?string $aggregateId, ?string $externalReference): array
    {
        return [
            'eventId' => 'event-1',
            'occurredAt' => self::AT,
            'tenantId' => 'tenant-1',
            'aggregateId' => $aggregateId,
            'externalReference' => $externalReference,
        ];
    }
}
