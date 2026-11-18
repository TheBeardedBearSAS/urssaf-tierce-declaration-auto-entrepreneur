<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Exception;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\DeclarationRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;

final class DeclarationExceptionsTest extends TestCase
{
    public function testARecordingFailureCarriesTheCertificatAndTheDeclarationIdOfTheAcceptedAnswer(): void
    {
        $failure = DeclarationRecordingFailed::after(DeclarationRecordId::fromString('record-1'), DeclarationWorld::accepted(), 3, 'declaration_record.concurrent_modification');

        static::assertSame('declaration_record.recording_failed', $failure->violationCode());
        static::assertSame(
            ['record_id' => 'record-1', 'attempts' => 3, 'last_code' => 'declaration_record.concurrent_modification', 'certificat' => '12345678', 'id_declaration' => 'W1dl9W2Oa4R6ab3UlQAqxF3'],
            $failure->params(),
        );
        static::assertSame('12345678', $failure->certificate());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $failure->declarationId());
        static::assertSame(
            'URSSAF accepted the declaration but the result could not be recorded after 3 attempt(s): never send it again, reconcile then resolve it as declared with the certificat (ADR 0009).',
            $failure->getMessage(),
        );
        static::assertNull($failure->getPrevious());
    }

    public function testAResolvedResultHasNoDeclarationIdParameter(): void
    {
        $failure = DeclarationRecordingFailed::after(
            DeclarationRecordId::fromString('record-1'),
            DeclarationResult::resolved(Certificate::fromString('12345678')),
            1,
            'declaration_record.not_found',
        );

        static::assertSame(['record_id' => 'record-1', 'attempts' => 1, 'last_code' => 'declaration_record.not_found', 'certificat' => '12345678'], $failure->params());
        static::assertNull($failure->declarationId());
    }

    public function testTheMessageAndTheParametersHoldNoSiretNorAmount(): void
    {
        $failure = DeclarationRecordingFailed::after(DeclarationRecordId::fromString('record-1'), DeclarationWorld::accepted(), 1, 'x.y');

        $dump = $failure->getMessage() . serialize($failure->params());
        static::assertStringNotContainsString(DeclarationWorld::SIRET, $dump);
    }
}
