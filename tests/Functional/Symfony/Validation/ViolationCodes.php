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

use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * The violation codes of the package, found by reading the sources: literal codes, then the codes composed from a
 * subject and a reason ("<subject>.<reason>"), which is how InvalidValue, the violation collector and the raw fields
 * build them. One scanner step per way of composing a code.
 *
 * A new way of composing a code must be taught here: {@see self::DYNAMIC_SUBJECT_FILES} lists the files that give a
 * variable subject to a factory, and fails the test until the new file is mapped.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ViolationCodes
{
    public const CODE = '[a-z][a-z0-9_]*';

    /** Directories scanned for codes, relative to the package root. */
    private const SCANNED = [
        'src/Domain',
        'src/Application',
        'src/Infrastructure',
    ];

    /**
     * Literals that look like a code and are not (a path, a host...).
     */
    public const NOT_CODES = [
        'tiersdecl.ae',
        'urssaf.tdae.declaration.v1',
        'urssaf.tdae.payment.v1',
    ];

    /**
     * Files that give a variable subject to a factory: their codes come from the callers, which a scanner step maps.
     * A new file fails the test until it is mapped.
     */
    public const DYNAMIC_SUBJECT_FILES = [
        'src/Domain/Sepa/SepaMandateAddress.php',
        'src/Domain/Validation/ViolationCollector.php',
    ];

    /**
     * Files that read raw fields under a variable key: the keys come from the callers, which a scanner step maps.
     */
    public const DYNAMIC_KEY_FILES = [
        'src/Domain/Contribution/Input/TurnoverFactory.php',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $suffixes = self::suffixMap();
        $codes = [];
        foreach (self::sources() as $path => $content) {
            $constants = self::constants($content);
            array_push(
                $codes,
                ...self::literalCodes($content),
                ...self::factoryCodes($path, $content, $constants, $suffixes),
                ...self::collectorCodes($content),
                ...self::addressCodes($content),
                ...self::fieldCodes($content),
                ...self::eurosCodes($content),
                ...self::prefixedCodes($path, $content, $constants),
            );
        }
        $codes = array_values(array_diff(array_unique($codes), self::NOT_CODES));
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * The files that give a variable subject to a factory.
     *
     * @return list<string>
     */
    public static function dynamicSubjectFiles(): array
    {
        $found = array_keys(array_filter(
            self::sources(),
            static fn(string $content): bool => preg_match('/InvalidValue::(?!because\b)\w+\(\s*\$subject\b/', $content) === 1,
        ));
        sort($found);

        return $found;
    }

    /**
     * The files that read raw fields under a variable key.
     *
     * @return list<string>
     */
    public static function dynamicKeyFiles(): array
    {
        $found = array_keys(array_filter(
            self::sources(),
            static fn(string $content): bool => preg_match('/\$fields->\w+\(\s*\$(?!this\b)\w+\s*\)/', $content) === 1,
        ));
        sort($found);

        return $found;
    }

    /**
     * @return array<string, string> the sources by path relative to the package root
     */
    public static function sources(): array
    {
        $sources = [];
        foreach (self::SCANNED as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                Assert::assertInstanceOf(SplFileInfo::class, $file);
                if ($file->getExtension() === 'php') {
                    $sources[substr($file->getPathname(), \strlen(self::root()) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($sources);

        return $sources;
    }

    /**
     * The shipped messages of a locale, by violation code.
     *
     * @return array<string, string>
     */
    public static function messages(string $locale): array
    {
        // @mago-expect analysis:mixed-assignment YAML is untyped; every entry is checked below.
        $parsed = Yaml::parseFile(self::root() . '/src/Bridge/Symfony/Resources/translations/urssaf_tierce_declaration_auto_entrepreneur.' . $locale . '.yaml');
        Assert::assertIsArray($parsed);
        $messages = [];
        foreach ($parsed as $code => $message) { // @mago-expect analysis:mixed-assignment narrowed by the assertion below.
            Assert::assertIsString($message);
            $messages[(string) $code] = $message;
        }

        return $messages;
    }

    public static function root(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * The reason suffix of every InvalidValue factory taking a subject, read from InvalidValue itself:
     * "empty" => ".empty", "tooLong" => ".too_long"...
     *
     * @return array<string, string>
     */
    public static function suffixMap(): array
    {
        $content = (string) file_get_contents(self::root() . '/src/Domain/Exception/InvalidValue.php');
        $map = [];
        foreach (\array_slice(explode('public static function ', $content), 1) as $method) {
            $name = [];
            if (preg_match('/^(\w+)\(string \$subject/', $method, $name) !== 1) {
                continue;
            }
            $suffix = [];
            Assert::assertSame(1, preg_match('/\$subject \. \'(\.[a-z_]+)\'/', $method, $suffix), 'No suffix in InvalidValue::' . $name[1]);
            $map[$name[1]] = $suffix[1];
        }

        return $map;
    }

    /**
     * The scalar constants of a file, `const NAME = 'value';`.
     *
     * @return array<string, string>
     */
    private static function constants(string $content): array
    {
        $matches = [];
        preg_match_all('/const ([A-Z_0-9]+) = \'(' . self::CODE . ')\';/', $content, $matches, PREG_SET_ORDER);
        $constants = [];
        foreach ($matches as $match) {
            $constants[$match[1]] = $match[2];
        }

        return $constants;
    }

    /**
     * @param array<string, string> $constants
     */
    private static function resolve(string $literal, string $constant, array $constants, string $path): string
    {
        if ($literal !== '') {
            return $literal;
        }
        Assert::assertArrayHasKey($constant, $constants, 'Unresolved constant ' . $constant . ' in ' . $path);

        return $constants[$constant];
    }

    /**
     * 1. The literal codes.
     *
     * @return list<string>
     */
    private static function literalCodes(string $content): array
    {
        $matches = [];
        preg_match_all('/\'(' . self::CODE . '(?:\.' . self::CODE . ')+)\'/', $content, $matches);

        return array_values($matches[1]);
    }

    /**
     * 2. The codes composed from a literal or constant subject by a factory of a known reason.
     *
     * @param array<string, string> $constants
     * @param array<string, string> $suffixes
     *
     * @return list<string>
     */
    private static function factoryCodes(string $path, string $content, array $constants, array $suffixes): array
    {
        $codes = [];
        $matches = [];
        preg_match_all('/InvalidValue::(\w+)\(\s*(?:\'(' . self::CODE . ')\'|self::([A-Z_0-9]+))/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (!\array_key_exists($match[1], $suffixes)) {
                continue;
            }
            $codes[] = self::resolve($match[2] ?? '', $match[3] ?? '', $constants, $path) . $suffixes[$match[1]];
        }
        foreach (['RecordNotFound::withIdentifier' => '.not_found', 'InvalidStateTransition::between' => '.invalid_state_transition'] as $call => $suffix) {
            $matches = [];
            preg_match_all('/' . preg_quote($call, '/') . '\(\s*(?:\'(' . self::CODE . ')\'|self::([A-Z_0-9]+))/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $codes[] = self::resolve($match[1] ?? '', $match[2] ?? '', $constants, $path) . $suffix;
            }
        }

        return $codes;
    }

    /**
     * 3. The codes of the violation collector: "<subject>.required" and "<subject>.out_of_range".
     *
     * @return list<string>
     */
    private static function collectorCodes(string $content): array
    {
        $codes = [];
        $matches = [];
        preg_match_all('/->(required|requiredInteger|optionalInteger|requiredBool)\(\s*\'[\w.\[\]]+\',\s*\'(' . self::CODE . ')\'/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match[1] !== 'optionalInteger') {
                $codes[] = $match[2] . '.required';
            }
            if ($match[1] === 'requiredInteger' || $match[1] === 'optionalInteger') {
                $codes[] = $match[2] . '.out_of_range';
            }
        }

        return $codes;
    }

    /**
     * 4. The codes of the SEPA address lines, whose subject is a parameter of a private method of SepaMandateAddress.
     *
     * @return list<string>
     */
    private static function addressCodes(string $content): array
    {
        $codes = [];
        $matches = [];
        preg_match_all('/self::check\(\$result, \'\w+\', \'(' . self::CODE . ')\'/', $content, $matches);
        foreach ($matches[1] as $subject) {
            $codes[] = $subject . '.empty';
            $codes[] = $subject . '.too_long';
        }

        return $codes;
    }

    /**
     * 5. The codes of the raw fields: "<key>.invalid_type" for every key read, "<key>.required" for the required ones.
     *
     * @return list<string>
     */
    private static function fieldCodes(string $content): array
    {
        $codes = [];
        $matches = [];
        preg_match_all('/\$fields->(\w+)\(\s*\'(' . self::CODE . ')\'/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (!\in_array($match[1], ['text', 'integer', 'boolean', 'requiredText', 'requiredInteger', 'requiredBoolean', 'required', 'optional', 'nested', 'optionalNested', 'items'], true)) {
                continue;
            }
            $codes[] = $match[2] . '.invalid_type';
            if (str_starts_with($match[1], 'required')) {
                $codes[] = $match[2] . '.required';
            }
        }

        return $codes;
    }

    /**
     * 6. The lines of a turnover, read under a variable key by TurnoverFactory: "<key>.invalid_type".
     *
     * @return list<string>
     */
    private static function eurosCodes(string $content): array
    {
        $matches = [];
        preg_match_all('/self::euros\(\$fields, \'(' . self::CODE . ')\'\)/', $content, $matches);

        return array_values(array_map(static fn(string $key): string => $key . '.invalid_type', $matches[1]));
    }

    /**
     * 7. The codes composed from a constant prefix and a literal suffix.
     *
     * @param array<string, string> $constants
     *
     * @return list<string>
     */
    private static function prefixedCodes(string $path, string $content, array $constants): array
    {
        $codes = [];
        $matches = [];
        preg_match_all('/self::([A-Z_0-9]+) \. \'(\.' . self::CODE . ')\'/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $codes[] = self::resolve('', $match[1], $constants, $path) . $match[2];
        }

        return $codes;
    }
}
