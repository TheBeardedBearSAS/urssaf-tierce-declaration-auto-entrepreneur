<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Submission;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class SubmissionErrorTest extends TestCase
{
    public function testKeepsEveryFieldVerbatim(): void
    {
        $error = SubmissionError::of('ER_API_050_0070', 'duplicate_declaration', 'Message', 'Description');

        static::assertSame('ER_API_050_0070', $error->code());
        static::assertSame('duplicate_declaration', $error->kind());
        static::assertSame('Message', $error->message());
        static::assertSame('Description', $error->description());
    }

    public function testMessageAndDescriptionAreOptional(): void
    {
        $error = SubmissionError::of('ERR_TECHNIQUE', 'technical', null, null);

        static::assertNull($error->message());
        static::assertNull($error->description());
    }

    public function testRejectsABlankCode(): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'submission_error_code.empty',
            static fn(): SubmissionError => SubmissionError::of(' ', 'technical', null, null),
        );

        static::assertSame('submission_error_code.empty', $exception->violationCode());
    }

    public function testRejectsABlankKind(): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'submission_error_kind.empty',
            static fn(): SubmissionError => SubmissionError::of('CODE', '  ', null, null),
        );

        static::assertSame('submission_error_kind.empty', $exception->violationCode());
    }

    public function testMasksTheMessageAndTheDescriptionInDebugDumps(): void
    {
        DomainAssertions::assertSensitiveParameters(SubmissionError::class, 'of', ['message', 'description']);
        DomainAssertions::assertSensitiveParameters(SubmissionError::class, '__construct', ['message', 'description']);
        static::assertSame(
            ['code' => 'C', 'kind' => 'k', 'message' => '***', 'description' => '***'],
            SubmissionError::of('C', 'k', 'M', 'secret')->__debugInfo(),
        );
        static::assertNull(SubmissionError::of('C', 'k', null, null)->__debugInfo()['description']);
        static::assertNull(SubmissionError::of('C', 'k', null, 'D')->__debugInfo()['message']);
    }
}
