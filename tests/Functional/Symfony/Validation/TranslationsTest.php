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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The shipped translations cover every violation code of the package, and only them, in every shipped locale. The codes
 * are found by reading the sources ({@see ViolationCodes}): a new code fails these tests until it is translated.
 *
 * One test per translation invariant, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final class TranslationsTest extends TestCase
{
    /** Locales shipped by the bundle. */
    private const LOCALES = ['en', 'fr'];

    private const DOMAIN = 'urssaf_tierce_declaration_auto_entrepreneur';

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryViolationCodeHasAMessage(string $locale): void
    {
        $missing = array_values(array_diff(ViolationCodes::all(), array_keys(ViolationCodes::messages($locale))));

        static::assertSame([], $missing, 'Violation codes without a message in "' . $locale . '".');
    }

    #[DataProvider('locales')]
    public function testNoMessageWithoutCode(string $locale): void
    {
        $orphans = array_values(array_diff(array_keys(ViolationCodes::messages($locale)), ViolationCodes::all()));

        static::assertSame([], $orphans, 'Messages of "' . $locale . '" for a code that no source produces.');
    }

    #[DataProvider('locales')]
    public function testEveryMessageIsAFilledSentence(string $locale): void
    {
        foreach (ViolationCodes::messages($locale) as $code => $message) {
            static::assertMatchesRegularExpression('/^' . ViolationCodes::CODE . '(\.' . ViolationCodes::CODE . ')+$/', $code);
            static::assertMatchesRegularExpression('/^\p{Lu}.+\.$/u', $message, $code);
        }
    }

    #[DataProvider('locales')]
    public function testEveryLocaleHasTheKeysOfTheEnglishFileInTheSameOrder(string $locale): void
    {
        static::assertSame(array_keys(ViolationCodes::messages('en')), array_keys(ViolationCodes::messages($locale)));
    }

    #[DataProvider('locales')]
    public function testTheKeysAreSorted(string $locale): void
    {
        $keys = array_keys(ViolationCodes::messages($locale));
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        static::assertSame($sorted, $keys);
    }

    public function testTheFrenchMessagesAreNotTheEnglishOnes(): void
    {
        $english = ViolationCodes::messages('en');
        $french = ViolationCodes::messages('fr');

        $untranslated = array_keys(array_filter($french, static fn(string $message, string $code): bool => $message === $english[$code], ARRAY_FILTER_USE_BOTH));

        static::assertSame([], $untranslated);
    }

    public function testTheScannerFindsEveryWayOfComposingACode(): void
    {
        $codes = ViolationCodes::all();

        foreach ([
            'declaration_record.max_attempts_reached', // literal
            'tenant_id.too_long', // factory with a constant subject
            'turnover.negative', // factory with a literal subject
            'address_line.too_long', // the subject of a line of the address
            'first_line.required', // raw field
            'effective_date.invalid_type', // raw field of any type
            'calendar_date.invalid_date', // constant prefix
            'mandate.not_found', // record not found
            'declaration_record.invalid_state_transition', // state transition
            'urssaf.rejected', // constant of the error translator
            'cipav_receipts.invalid_type', // key of a turnover line
        ] as $code) {
            static::assertContains($code, $codes);
        }
        static::assertNotContains('tiersdecl.ae', $codes, 'A token scope is not a code.');
        static::assertNotContains('urssaf.tdae.declaration.v1', $codes, 'A fingerprint version is not a code.');
    }

    public function testTheDynamicSubjectFilesAreKnown(): void
    {
        static::assertSame(ViolationCodes::DYNAMIC_SUBJECT_FILES, ViolationCodes::dynamicSubjectFiles());
    }

    public function testTheDynamicKeyFilesAreKnown(): void
    {
        static::assertSame(ViolationCodes::DYNAMIC_KEY_FILES, ViolationCodes::dynamicKeyFiles());
    }

    public function testTheNonCodesAreStillInTheSources(): void
    {
        $content = implode("\n", ViolationCodes::sources());

        foreach (ViolationCodes::NOT_CODES as $literal) {
            static::assertStringContainsString("'" . $literal . "'", $content);
        }
    }

    public function testTheSuffixMapCoversEveryFactory(): void
    {
        $map = ViolationCodes::suffixMap();
        ksort($map);

        static::assertSame(
            [
                'empty' => '.empty',
                'invalidFormat' => '.invalid_format',
                'negative' => '.negative',
                'outOfRange' => '.out_of_range',
                'tooLong' => '.too_long',
            ],
            $map,
        );
    }

    public function testTheFrameworkTranslatorLoadsTheDomain(): void
    {
        $app = Harness::boot();
        $translator = $app->privateService('translator', TranslatorInterface::class);

        static::assertSame(
            'Le champ « SIRET » est obligatoire.',
            $translator->trans('siret.required', [], self::DOMAIN, 'fr'),
        );
        static::assertSame(
            'SIRET is required.',
            $translator->trans('siret.required', [], self::DOMAIN, 'en'),
        );
    }
}
