<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Port\Urssaf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\AccessToken;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionEstimator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * Parameters that carry personal, bank or secret data are redacted from stack traces (ADR 0010).
 */
final class SensitiveParametersTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, list<string>}>
     */
    public static function provideSensitiveParameters(): iterable
    {
        yield 'ApiError::of' => [ApiError::class, 'of', ['message', 'description']];
        yield 'Credentials::of' => [Credentials::class, 'of', ['clientSecret']];
        yield 'AccessToken::of' => [AccessToken::class, 'of', ['value']];
        yield 'TokenStore::save' => [TokenStore::class, 'save', ['token']];
        yield 'MandateNotifier::notifyMandate' => [MandateNotifier::class, 'notifyMandate', ['account']];
        yield 'MandateNotifier::cancelMandate' => [MandateNotifier::class, 'cancelMandate', ['account']];
        yield 'ContributionPayer::payContributions' => [ContributionPayer::class, 'payContributions', ['order']];
        yield 'SepaMandateRegistrar::registerSepaMandate' => [
            SepaMandateRegistrar::class,
            'registerSepaMandate',
            ['mandate'],
        ];
        yield 'AccountReader::findAccount' => [AccountReader::class, 'findAccount', []];
        yield 'ContributionEstimator::estimateContributions' => [
            ContributionEstimator::class,
            'estimateContributions',
            [],
        ];
        yield 'TurnoverDeclarer::declareTurnover' => [TurnoverDeclarer::class, 'declareTurnover', []];
        yield 'SepaMandateRevoker::revokeSepaMandate' => [SepaMandateRevoker::class, 'revokeSepaMandate', []];
        yield 'SepaMandateFinder::listSepaMandates' => [SepaMandateFinder::class, 'listSepaMandates', []];
    }

    /**
     * @param class-string $class
     * @param list<string> $expected
     */
    #[DataProvider('provideSensitiveParameters')]
    public function testSensitiveParameters(string $class, string $method, array $expected): void
    {
        DomainAssertions::assertSensitiveParameters($class, $method, $expected);
    }
}
