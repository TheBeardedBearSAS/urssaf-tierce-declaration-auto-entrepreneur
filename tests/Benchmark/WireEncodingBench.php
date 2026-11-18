<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Benchmark;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\MandateQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\QueryEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Encoding of the request bodies and of the mandate query string sent to URSSAF.
 */
final readonly class WireEncodingBench
{
    private Turnover $turnover;

    private TurnoverDeclaration $declaration;

    private PaymentOrder $paymentOrder;

    private Siret $siret;

    private NewSepaMandate $mandate;

    private AccountReference $account;

    public function __construct()
    {
        $this->turnover = WireSamples::turnover();
        $this->declaration = WireSamples::declaration();
        $this->paymentOrder = WireSamples::paymentOrder();
        $this->siret = WireSamples::siret();
        $this->mandate = WireSamples::newSepaMandate();
        $this->account = WireSamples::accountReference();
    }

    public function benchEncodeAnEstimateRequest(): void
    {
        JsonEncoder::encode(EstimateRequestMapper::body($this->turnover));
    }

    public function benchEncodeADeclarationRequest(): void
    {
        JsonEncoder::encode(DeclarationRequestMapper::body($this->declaration));
    }

    public function benchEncodeAPaymentRequest(): void
    {
        JsonEncoder::encode(PaymentRequestMapper::body($this->paymentOrder));
    }

    public function benchEncodeASepaMandateRequest(): void
    {
        JsonEncoder::encode(SepaMandateRequestMapper::body($this->siret, $this->mandate));
    }

    public function benchBuildAMandateQuery(): string
    {
        return QueryEncoding::build(MandateQuery::of($this->account));
    }
}
