<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\BundleConfiguration;

/**
 * The default persistence driver follows the installed ORM (ADR 0015).
 */
final class DefaultDriverTest extends TestCase
{
    public function testTheDriverIsDoctrineWhenTheOrmIsInstalled(): void
    {
        static::assertSame('doctrine', BundleConfiguration::defaultDriverFor(true));
    }

    public function testTheDriverIsInMemoryWithoutTheOrm(): void
    {
        static::assertSame('in_memory', BundleConfiguration::defaultDriverFor(false));
    }

    public function testTheRuntimeDefaultIsDoctrineBecauseTheOrmIsADevelopmentDependency(): void
    {
        static::assertSame('doctrine', BundleConfiguration::defaultDriver());
    }
}
