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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class ApiErrorTest extends TestCase
{
    public function testItKeepsCodeMessageAndDescriptionVerbatim(): void
    {
        $error = ApiError::of('PARAM_INVALIDE', 'Un des paramètres de votre demande est invalide', 'Le paramètre dateNaissance est invalide');

        static::assertSame('PARAM_INVALIDE', $error->code());
        static::assertSame('Un des paramètres de votre demande est invalide', $error->message());
        static::assertSame('Le paramètre dateNaissance est invalide', $error->description());
    }

    public function testMessageAndDescriptionMayBeAbsent(): void
    {
        $error = ApiError::of('ERR_TECHNIQUE', null, null);

        static::assertNull($error->message());
        static::assertNull($error->description());
    }

    public function testTheCodeIsTrimmed(): void
    {
        $error = ApiError::of(" PE_INEXISTANT \n", null, null);

        static::assertSame('PE_INEXISTANT', $error->code());
        static::assertTrue($error->hasCode('PE_INEXISTANT'));
    }

    public function testAnEmptyOrBlankCodeIsRefused(): void
    {
        foreach (['', " \t"] as $code) {
            $exception = DomainAssertions::assertViolationCode('api_error.empty_code', static fn(): ApiError => ApiError::of($code, 'm', 'd'));

            static::assertSame('An URSSAF error must carry a code.', $exception->getMessage());
        }
    }

    public function testHasCodeIsExact(): void
    {
        $error = ApiError::of('ERR_TECHNIQUE', null, null);

        static::assertTrue($error->hasCode('ERR_TECHNIQUE'));
        static::assertFalse($error->hasCode('err_technique'));
        static::assertFalse($error->hasCode('ERREUR_TECHNIQUE'));
    }

    public function testEqualsComparesTheThreeFields(): void
    {
        $error = ApiError::of('A', 'm', 'd');

        static::assertTrue($error->equals(ApiError::of('A', 'm', 'd')));
        static::assertFalse($error->equals(ApiError::of('B', 'm', 'd')));
        static::assertFalse($error->equals(ApiError::of('A', 'x', 'd')));
        static::assertFalse($error->equals(ApiError::of('A', 'm', 'x')));
        static::assertFalse($error->equals(ApiError::of('A', null, 'd')));
        static::assertFalse($error->equals(ApiError::of('A', 'm', null)));
    }

    public function testMessageAndDescriptionAreMaskedInDebugOutput(): void
    {
        $error = ApiError::of('A', 'echoes a message', 'echoes a value');

        static::assertSame(['code' => 'A', 'message' => '***', 'description' => '***'], $error->__debugInfo());

        $dump = print_r($error, true);
        static::assertStringNotContainsString('echoes a message', $dump);
        static::assertStringNotContainsString('echoes a value', $dump);
        static::assertStringContainsString('***', $dump);
        static::assertSame(
            ['code' => 'A', 'message' => null, 'description' => null],
            ApiError::of('A', null, null)->__debugInfo(),
        );
        static::assertSame(
            ['code' => 'A', 'message' => '***', 'description' => null],
            ApiError::of('A', 'm', null)->__debugInfo(),
        );
        static::assertSame(
            ['code' => 'A', 'message' => null, 'description' => '***'],
            ApiError::of('A', null, 'd')->__debugInfo(),
        );
    }

    public function testTheMessageAndDescriptionParametersAreSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(ApiError::class, 'of', ['message', 'description']);
    }
}
