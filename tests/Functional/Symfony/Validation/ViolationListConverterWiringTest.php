<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Validation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Validation\ViolationListConverter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The converter is wired by the bundle and translates with the shipped messages.
 */
final class ViolationListConverterWiringTest extends TestCase
{
    public function testTheConverterIsRegisteredWithTheOptionalTranslator(): void
    {
        // Compiled afresh: the booted test containers are cached by configuration, not by source code.
        $definition = (new TestKernel())->definitions()->getDefinition(ViolationListConverter::class);

        static::assertTrue($definition->isPublic());
        static::assertEquals([new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE)], $definition->getArguments());
    }

    public function testTheConverterIsPublicAndTranslatesWithTheShippedMessages(): void
    {
        $app = Harness::boot();
        $app->privateService('translator', LocaleAwareInterface::class)->setLocale('fr');
        $result = ValidationResult::of(Violation::error('siret', 'siret.required', 'The SIRET is required.'));

        $violation = $app->get(ViolationListConverter::class)->convert($result, 'declaration')->get(0);

        static::assertInstanceOf(ConstraintViolation::class, $violation);
        static::assertSame('Le champ « SIRET » est obligatoire.', $violation->getMessage());
        static::assertSame('declaration.siret', $violation->getPropertyPath());
    }
}
