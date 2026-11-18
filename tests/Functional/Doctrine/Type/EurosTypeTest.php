<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\EurosType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

/**
 * Conversion scenarios of the whole-euros column type: amounts are not personal data, the errors may quote them.
 */
final class EurosTypeTest extends TestCase
{
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        DoctrineIntegration::registerTypes();
        $this->platform = new SQLitePlatform();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function amounts(): iterable
    {
        yield 'zero' => [0];
        yield 'positive' => [1_500];
        yield 'negative' => [-250];
        yield 'maximum' => [Euros::MAX];
        yield 'minimum' => [Euros::MIN];
    }

    #[DataProvider('amounts')]
    public function testItStoresTheWholeEurosAndRebuildsTheAmount(int $amount): void
    {
        $type = Type::getType(EurosType::NAME);

        static::assertSame($amount, $type->convertToDatabaseValue(Euros::of($amount), $this->platform));
        static::assertEquals(Euros::of($amount), $type->convertToPHPValue($amount, $this->platform));
    }

    #[DataProvider('amounts')]
    public function testItAcceptsTheIntegerAsAStringThatPdoMayReturn(int $amount): void
    {
        static::assertEquals(
            Euros::of($amount),
            Type::getType(EurosType::NAME)->convertToPHPValue((string) $amount, $this->platform),
        );
    }

    public function testNullStaysNull(): void
    {
        $type = Type::getType(EurosType::NAME);

        static::assertNull($type->convertToDatabaseValue(null, $this->platform));
        static::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function testItIsAnIntegerColumnBoundAsAnInteger(): void
    {
        $type = Type::getType(EurosType::NAME);

        static::assertSame($this->platform->getIntegerTypeDeclarationSQL([]), $type->getSQLDeclaration([], $this->platform));
        static::assertSame(ParameterType::INTEGER, $type->getBindingType());
    }

    public function testItRefusesAForeignPhpValue(): void
    {
        foreach ([1_500, '1500', 15.5] as $foreign) {
            try {
                Type::getType(EurosType::NAME)->convertToDatabaseValue($foreign, $this->platform);
                static::fail('Expected a conversion failure.');
            } catch (ConversionException $exception) {
                static::assertStringEndsWith('types: ' . Euros::class . ', null.', $exception->getMessage());
            }
        }
    }

    public function testItRefusesAStoredValueOfAnotherType(): void
    {
        foreach ([15.5, true, ['1']] as $stored) {
            try {
                Type::getType(EurosType::NAME)->convertToPHPValue($stored, $this->platform);
                static::fail('Expected a conversion failure.');
            } catch (ConversionException $exception) {
                static::assertStringEndsWith('types: int, string, null.', $exception->getMessage());
            }
        }
    }

    public function testItRefusesAStoredStringThatIsNotAnInteger(): void
    {
        foreach (['abc', '1.5', '', '1e3'] as $stored) {
            try {
                Type::getType(EurosType::NAME)->convertToPHPValue($stored, $this->platform);
                static::fail('Expected a conversion failure.');
            } catch (ConversionException $exception) {
                static::assertStringContainsString('Expected format "integer"', $exception->getMessage());
                static::assertNull($exception->getPrevious());
            }
        }
    }

    public function testItReportsAnAmountOutsideTheRangeOfTheDomain(): void
    {
        foreach ([Euros::MAX + 1, Euros::MIN - 1, (string) (Euros::MAX + 1)] as $stored) {
            try {
                Type::getType(EurosType::NAME)->convertToPHPValue($stored, $this->platform);
                static::fail('Expected a conversion failure.');
            } catch (ConversionException $exception) {
                static::assertStringContainsString('Expected format "' . Euros::class . '"', $exception->getMessage());
                static::assertInstanceOf(InvalidValue::class, $exception->getPrevious());
            }
        }
    }
}
