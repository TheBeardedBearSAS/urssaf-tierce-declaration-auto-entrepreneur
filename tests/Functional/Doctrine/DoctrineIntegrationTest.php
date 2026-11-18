<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\NativeLazyOrmConfiguration;

final class DoctrineIntegrationTest extends TestCase
{
    public function testItRegistersEveryTypeOnceAndIsIdempotent(): void
    {
        DoctrineIntegration::registerTypes();
        DoctrineIntegration::registerTypes();

        static::assertCount(17, DoctrineIntegration::types());
        foreach (DoctrineIntegration::types() as $name => $class) {
            static::assertStringStartsWith(DoctrineIntegration::TABLE_PREFIX, $name);
            static::assertSame(Type::getType($name)::class, $class);
            static::assertSame($name, Type::lookupName(Type::getType($name)));
        }
    }

    public function testTheTypesAreListedInTheDocumentedOrder(): void
    {
        static::assertSame(
            [
                'urssaf_tdae_tenant_id',
                'urssaf_tdae_siret',
                'urssaf_tdae_account_id',
                'urssaf_tdae_mandate_id',
                'urssaf_tdae_declaration_record_id',
                'urssaf_tdae_payment_record_id',
                'urssaf_tdae_payment_reference',
                'urssaf_tdae_rum',
                'urssaf_tdae_declaration_fingerprint',
                'urssaf_tdae_payment_fingerprint',
                'urssaf_tdae_calendar_date',
                'urssaf_tdae_birth_date',
                'urssaf_tdae_instant',
                'urssaf_tdae_euros',
                'urssaf_tdae_declaration_result',
                'urssaf_tdae_payment_result',
                'urssaf_tdae_submission_errors',
            ],
            array_keys(DoctrineIntegration::types()),
        );
    }

    #[RunInSeparateProcess]
    public function testItRegistersTheMissingTypesWhenSomeAreAlreadyRegistered(): void
    {
        $names = array_keys(DoctrineIntegration::types());
        $first = $names[0];
        static::assertFalse(Type::hasType($first));
        Type::addType($first, DoctrineIntegration::types()[$first]);

        DoctrineIntegration::registerTypes();

        foreach ($names as $name) {
            static::assertTrue(Type::hasType($name));
        }
    }

    public function testTheMappingDirectoryHoldsOneFilePerMappedClass(): void
    {
        $directory = DoctrineIntegration::mappingDirectory();
        $files = glob($directory . '/*.orm.xml');

        static::assertDirectoryExists($directory);
        static::assertIsArray($files);
        static::assertCount(12, $files);
        static::assertSame(
            [
                'Bridge\\Doctrine\\Entity\\ActiveMandate',
                'Bridge\\Doctrine\\Entity\\CurrentDeclaration',
                'Bridge\\Doctrine\\Entity\\CurrentPayment',
                'Bridge\\Doctrine\\Entity\\OutboxMessage',
                'Domain\\Account\\AccountStatus',
                'Domain\\Account\\AeAccount',
                'Domain\\Declaration\\DeclarationRecord',
                'Domain\\Declaration\\DeclarationStatus',
                'Domain\\Mandate\\Mandate',
                'Domain\\Mandate\\MandateTimeline',
                'Domain\\Payment\\PaymentRecord',
                'Domain\\Payment\\PaymentStatus',
            ],
            self::mappedClasses(),
        );
    }

    public function testTheMetadataDriverMapsTheAggregatesAndIgnoresOtherClasses(): void
    {
        $driver = DoctrineIntegration::mappingDriver();

        static::assertFalse($driver->isTransient(AeAccount::class));
        static::assertFalse($driver->isTransient(Mandate::class));
        static::assertFalse($driver->isTransient(DeclarationRecord::class));
        static::assertFalse($driver->isTransient(PaymentRecord::class));
        static::assertTrue($driver->isTransient(DoctrineIntegration::class));
    }

    public function testItProvidesTheDoctrineBundleConfiguration(): void
    {
        static::assertSame(
            [
                'dbal' => ['types' => DoctrineIntegration::types()],
                'orm' => [
                    'mappings' => [
                        'UrssafTierceDeclarationAutoEntrepreneur' => [
                            'type' => 'xml',
                            'is_bundle' => false,
                            'dir' => DoctrineIntegration::mappingDirectory(),
                            'prefix' => 'TheBeardedBear\\UrssafTierceDeclarationAutoEntrepreneur',
                            'alias' => 'UrssafTierceDeclarationAutoEntrepreneur',
                        ],
                    ],
                ],
            ],
            DoctrineIntegration::doctrineBundleConfigurationWith([]),
        );
    }

    public function testItAddsTheGivenOrmOptionsToTheBundleConfiguration(): void
    {
        $configuration = DoctrineIntegration::doctrineBundleConfigurationWith(['enable_native_lazy_objects' => true]);

        static::assertTrue($configuration['orm']['enable_native_lazy_objects'] ?? false);
        static::assertSame(DoctrineIntegration::doctrineBundleConfigurationWith([])['orm']['mappings'], $configuration['orm']['mappings']);
        static::assertSame(DoctrineIntegration::types(), $configuration['dbal']['types']);
    }

    public function testTheNativeLazyObjectsAreDetectedFromTheRuntimeByDefault(): void
    {
        static::assertSame(
            DoctrineIntegration::nativeLazyObjectsAvailable(),
            \array_key_exists('enable_native_lazy_objects', DoctrineIntegration::doctrineBundleConfiguration()['orm']),
        );
        static::assertSame(
            DoctrineIntegration::nativeLazyObjectsOptions(\PHP_VERSION_ID, Configuration::class) !== [],
            DoctrineIntegration::nativeLazyObjectsAvailable(),
        );
        static::assertSame(
            \PHP_VERSION_ID >= 80_400 && (new ReflectionClass(Configuration::class))->hasMethod('enableNativeLazyObjects'),
            DoctrineIntegration::nativeLazyObjectsAvailable(),
        );
    }

    public function testTheNativeLazyObjectsNeedPhp84AndAnOrmThatImplementsThem(): void
    {
        $enabled = ['enable_native_lazy_objects' => true];

        static::assertSame($enabled, DoctrineIntegration::nativeLazyObjectsOptions(80_400, NativeLazyOrmConfiguration::class));
        static::assertSame($enabled, DoctrineIntegration::nativeLazyObjectsOptions(80_500, NativeLazyOrmConfiguration::class));
        static::assertSame([], DoctrineIntegration::nativeLazyObjectsOptions(80_399, NativeLazyOrmConfiguration::class));
        static::assertSame([], DoctrineIntegration::nativeLazyObjectsOptions(80_400, stdClass::class));
    }

    /**
     * @return list<string> mapped classes without the common namespace prefix
     */
    private static function mappedClasses(): array
    {
        $classes = DoctrineIntegration::mappingDriver()->getAllClassNames();
        $prefix = DoctrineIntegration::MAPPING_NAMESPACE . '\\';
        $names = array_map(static fn(string $class): string => substr($class, \strlen($prefix)), $classes);
        sort($names);

        return $names;
    }
}
