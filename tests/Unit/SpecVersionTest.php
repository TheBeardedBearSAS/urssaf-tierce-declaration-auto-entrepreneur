<?php

declare(strict_types=1);

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\SpecVersion;

final class SpecVersionTest extends TestCase
{
    public function testItExposesTheImplementedSpecificationVersion(): void
    {
        static::assertMatchesRegularExpression('/^1\.5\.0$/', SpecVersion::CURRENT);
    }
}
