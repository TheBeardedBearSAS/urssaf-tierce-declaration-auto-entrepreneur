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
use ReflectionMethod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionEstimator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;

/**
 * Guards the documented contract of the sub-ports (ADR 0003, ADR 0012): every method says what it throws when the
 * tenant has no credentials or the local limiter refuses, and only the writes can end in an unknown outcome.
 */
final class PortDocblocksTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, bool}> port, method, may end in an unknown outcome
     */
    public static function provideMethods(): iterable
    {
        yield '020' => [AccountReader::class, 'findAccount', false];
        yield '030' => [MandateNotifier::class, 'notifyMandate', true];
        yield '031' => [MandateNotifier::class, 'cancelMandate', true];
        yield '040' => [ContributionEstimator::class, 'estimateContributions', false];
        yield '050' => [TurnoverDeclarer::class, 'declareTurnover', true];
        yield '060' => [ContributionPayer::class, 'payContributions', true];
        yield '070' => [SepaMandateRegistrar::class, 'registerSepaMandate', true];
        yield '071' => [SepaMandateRevoker::class, 'revokeSepaMandate', true];
        yield '075' => [SepaMandateFinder::class, 'listSepaMandates', false];
    }

    /**
     * @param class-string $port
     */
    #[DataProvider('provideMethods')]
    public function testEveryMethodDocumentsItsFailures(string $port, string $method, bool $mayBeUnknown): void
    {
        $doc = (string) (new ReflectionMethod($port, $method))->getDocComment();

        static::assertStringContainsString('@throws RecordNotFound', $doc);
        static::assertStringContainsString('@throws RateLimitExceeded', $doc);
        static::assertStringContainsString('@throws UrssafRequestRejected', $doc);
        static::assertStringContainsString('@throws UrssafAuthenticationFailed', $doc);
        static::assertStringContainsString('@throws UrssafUnavailable', $doc);
        static::assertSame($mayBeUnknown, str_contains($doc, '@throws OutcomeUnknown'));
    }
}
