<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Opaque fingerprint of the content of a turnover declaration (SHA-256, 64 lowercase hexadecimal characters), used for
 * idempotency (ADR 0009 "same content"). It covers the tenant, the SIRET, the period and the five turnover lines. The
 * SIRET is hashed, never kept: the fingerprint holds no readable personal data.
 *
 * Canonical form (stable: stored by the Doctrine bridge, ADR 0016): `hash('sha256', parts)` where every part is encoded `strlen(p) . ':' . p . ';'`,
 * so that an absent line (`''`) differs from `'0'` and no separator can be forged by a tenant identifier.
 */
final readonly class DeclarationFingerprint
{
    private const PATTERN = '/^[0-9a-f]{64}$/D';
    private const VERSION = 'urssaf.tdae.declaration.v1';

    private function __construct(private string $value) {}

    /**
     * SHA-256 of the canonical form of the declaration.
     */
    public static function of(TenantId $tenantId, Siret $siret, DeclarationPeriod $period, Turnover $turnover): self
    {
        $parts = [
            self::VERSION,
            $tenantId->value(),
            $siret->toString(),
            $period->periodicity()->value,
            (string) $period->year(),
            (string) DeclarationPeriods::numberOf($period),
            self::line($turnover->sales()),
            self::line($turnover->furnishedRental()),
            self::line($turnover->commercialServices()),
            self::line($turnover->otherServices()),
            self::line($turnover->cipavReceipts()),
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
     * @throws InvalidValue "declaration_fingerprint.invalid_format"
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidValue::invalidFormat('declaration_fingerprint', '64 lowercase hexadecimal characters');
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

    private static function line(?Euros $amount): string
    {
        return $amount instanceof Euros ? (string) $amount->toInt() : '';
    }
}
