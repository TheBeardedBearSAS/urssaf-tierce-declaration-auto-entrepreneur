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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Input\TurnoverDeclarationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Input\PaymentOrderFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\NewSepaMandateFactory;

/**
 * Validation and building of the host input arrays (Domain factories), with every optional field set.
 */
final readonly class ValidationBench
{
    private TurnoverDeclarationFactory $declarationFactory;

    private PaymentOrderFactory $paymentFactory;

    private NewSepaMandateFactory $mandateFactory;

    /** @var array<string, mixed> */
    private array $declaration;

    /** @var array<string, mixed> */
    private array $payment;

    /** @var array<string, mixed> */
    private array $mandate;

    public function __construct()
    {
        $this->declarationFactory = new TurnoverDeclarationFactory();
        $this->paymentFactory = new PaymentOrderFactory();
        $this->mandateFactory = new NewSepaMandateFactory();
        $this->declaration = [
            'period' => ['year' => 2019, 'quarter' => 3],
            'turnover' => [
                'sales' => 1000,
                'furnished_rental' => 2000,
                'commercial_services' => 3000,
                'other_services' => 4000,
                'cipav_receipts' => 0,
            ],
        ];
        $this->payment = [
            'period' => ['year' => 2019, 'month' => 12],
            'amount' => 88,
            'iban' => 'FR7630001007941234567890185',
            'rum' => 'T1234567891234567891234568912345678',
        ];
        $this->mandate = [
            'bic' => 'ABCDEFGHIJK',
            'iban' => 'FR7630001007941234567890185',
            'account_holder' => 'Maxime LEFORESTIER',
            'address' => [
                'first_line' => '13 boulevard vauban',
                'second_line' => 'Bat A',
                'postal_code' => '59000',
                'city' => 'LILLE',
                'country' => 'FRANCE',
            ],
        ];
    }

    public function benchValidateATurnoverDeclaration(): void
    {
        $this->declarationFactory->validate($this->declaration);
    }

    public function benchBuildATurnoverDeclaration(): void
    {
        $this->declarationFactory->fromInput($this->declaration);
    }

    public function benchValidateAPaymentOrder(): void
    {
        $this->paymentFactory->validate($this->payment);
    }

    public function benchBuildAPaymentOrder(): void
    {
        $this->paymentFactory->fromInput($this->payment);
    }

    public function benchValidateANewSepaMandate(): void
    {
        $this->mandateFactory->validate($this->mandate);
    }

    public function benchBuildANewSepaMandate(): void
    {
        $this->mandateFactory->fromInput($this->mandate);
    }
}
