<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ApiPaths;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\MandateQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaRevocationQuery;

/**
 * One realistic request per operation, built like the gateway builds them (fictitious data).
 */
final class ApiRequests
{
    private function __construct() {}

    public static function of(Operation $operation): ApiRequest
    {
        $siret = WireSamples::siret();

        $request = match ($operation) {
            Operation::FindAccount => ApiRequest::get($operation, ApiPaths::findAccount(), AccountQuery::of($siret)),
            Operation::NotifyMandate => ApiRequest::postWithoutBody(
                $operation,
                ApiPaths::notifyMandate(),
                MandateQuery::of(WireSamples::accountReference()),
            ),
            Operation::CancelMandate => ApiRequest::delete(
                $operation,
                ApiPaths::cancelMandate(),
                MandateQuery::of(WireSamples::accountReference()),
            ),
            Operation::Estimate => ApiRequest::post(
                $operation,
                ApiPaths::estimateContributions(),
                AccountQuery::of($siret),
                EstimateRequestMapper::body(WireSamples::turnover()),
            ),
            Operation::Declare => ApiRequest::post(
                $operation,
                ApiPaths::declareTurnover(),
                AccountQuery::of($siret),
                DeclarationRequestMapper::body(WireSamples::declaration()),
            ),
            Operation::Pay => ApiRequest::post(
                $operation,
                ApiPaths::payContributions(),
                AccountQuery::of($siret),
                PaymentRequestMapper::body(WireSamples::paymentOrder()),
            ),
            Operation::RegisterSepaMandate => ApiRequest::post(
                $operation,
                ApiPaths::registerSepaMandate(),
                [],
                SepaMandateRequestMapper::body($siret, WireSamples::newSepaMandate()),
            ),
            Operation::RevokeSepaMandate => ApiRequest::delete(
                $operation,
                ApiPaths::revokeSepaMandate($siret, WireSamples::revocation()->rum()),
                SepaRevocationQuery::of(WireSamples::revocation()),
            ),
            Operation::ListSepaMandates => ApiRequest::get($operation, ApiPaths::listSepaMandates(), AccountQuery::of($siret)),
        };

        return $request->about($siret);
    }
}
