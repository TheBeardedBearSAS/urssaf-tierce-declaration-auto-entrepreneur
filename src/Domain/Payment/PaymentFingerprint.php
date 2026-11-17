<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Opaque fingerprint of the content of a payment order (SHA-256, 64 lowercase hexadecimal characters), used for
 * idempotency (ADR 0009 "same content"). It covers the tenant, the SIRET, the period, the amount, the RUM and whether the
 * order carries an IBAN, never the IBAN itself (ADR 0010 item 2): it is not an IBAN hash.
 *
 * Canonical form (stable: stored by the Doctrine bridge, ADR 0016): `hash('sha256', parts)` where every part is encoded `strlen(p) . ':' . p . ';'`,
 * so that an absent RUM (`''`) differs from any value and no separator can be forged by a tenant identifier.
 */
final readonly class PaymentFingerprint
{
    private const PATTERN = '/^[0-9a-f]{64}$/D';
    private const VERSION = 'urssaf.tdae.payment.v1';

    private function __construct(private string $value) {}

    /**
     * SHA-256 of the canonical form of the payment order.
     */
    public static function of(TenantId $tenantId, Siret $siret, PaymentOrder $order): self
    {
        $period = $order->period();
        $parts = [
            self::VERSION,
            $tenantId->value(),
            $siret->toString(),
            $period->periodicity()->value,
            (string) $period->year(),
            (string) DeclarationPeriods::numberOf($period),
            (string) $order->amount()->toInt(),
            $order->rum()?->value() ?? '',
            $order->hasIban() ? '1' : '0',
        ];

        $canonical = '';
        foreach ($parts as $part) {
            $canonical .= self::encode($part);
        }

        return new self(hash('sha256', $canonical));
    }

    private static function encode(string $part): string
    {
        return \strlen($part) . ':' . $part . ';';
    }

    /**
     * @throws InvalidValue "payment_fingerprint.invalid_format"
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidValue::invalidFormat('payment_fingerprint', '64 lowercase hexadecimal characters');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
