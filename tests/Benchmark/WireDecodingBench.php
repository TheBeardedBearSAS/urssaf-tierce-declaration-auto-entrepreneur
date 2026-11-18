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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorPayloadParser;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;

/**
 * Decoding of the official URSSAF responses, from the raw JSON string, and parsing of the error payloads.
 */
final readonly class WireDecodingBench
{
    private ErrorPayloadParser $errorParser;

    private string $account;

    private string $estimate;

    private string $declaration;

    private string $wrappedDeclaration;

    private string $payment;

    private string $sepaMandate;

    private string $sepaMandates;

    private string $errorObject;

    private string $errorList;

    public function __construct()
    {
        $this->errorParser = new ErrorPayloadParser();
        $this->account = Fixtures::load('account_response');
        $this->estimate = Fixtures::load('estimate_response');
        $this->declaration = Fixtures::load('declare_response');
        $this->wrappedDeclaration = Fixtures::wrapped('declare_response', ResponseEnvelope::ESTIMATE_OR_DECLARATION);
        $this->payment = Fixtures::load('pay_response');
        $this->sepaMandate = Fixtures::load('sepa_mandate_response');
        $this->sepaMandates = Fixtures::load('sepa_mandates_search_response');
        $this->errorObject = Fixtures::load('error_cpte_inconnu');
        $this->errorList = '[' . Fixtures::load('error_sepa_generic') . ']';
    }

    public function benchDecodeAnAccountResponse(): void
    {
        AccountResponseMapper::map(JsonDecoder::decode($this->account));
    }

    public function benchDecodeAnEstimateResponse(): void
    {
        EstimateResponseMapper::map(JsonDecoder::decode($this->estimate));
    }

    public function benchDecodeADeclarationResponse(): void
    {
        DeclarationResponseMapper::map(JsonDecoder::decode($this->declaration));
    }

    public function benchDecodeAWrappedDeclarationResponse(): void
    {
        DeclarationResponseMapper::map(JsonDecoder::decode($this->wrappedDeclaration));
    }

    public function benchDecodeAPaymentResponse(): void
    {
        PaymentResponseMapper::map(JsonDecoder::decode($this->payment));
    }

    public function benchDecodeASepaMandateResponse(): void
    {
        SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode($this->sepaMandate));
    }

    public function benchDecodeASepaMandateList(): void
    {
        SepaMandateResponseMapper::mapList(JsonDecoder::decode($this->sepaMandates));
    }

    public function benchParseASingleObjectErrorPayload(): void
    {
        $this->errorParser->parseBody($this->errorObject);
    }

    public function benchParseAListErrorPayload(): void
    {
        $this->errorParser->parseBody($this->errorList);
    }
}
