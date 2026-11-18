<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * Postal address of the holder of a new SEPA mandate (method 070), kept verbatim. Personal data (ADR 0010): masked
 * when dumped.
 */
final readonly class SepaMandateAddress
{
    public const MAX_LINE_LENGTH = 32;
    public const MAX_POSTAL_CODE_LENGTH = 5;
    public const MAX_CITY_LENGTH = 26;
    public const MAX_COUNTRY_LENGTH = 80;

    private function __construct(
        #[SensitiveParameter]
        private string $firstLine,
        #[SensitiveParameter]
        private ?string $secondLine,
        #[SensitiveParameter]
        private string $postalCode,
        #[SensitiveParameter]
        private string $city,
        #[SensitiveParameter]
        private string $country,
    ) {}

    /**
     * @throws ValidationFailed with every violation
     */
    public static function of(
        #[SensitiveParameter]
        string $firstLine,
        #[SensitiveParameter]
        ?string $secondLine,
        #[SensitiveParameter]
        string $postalCode,
        #[SensitiveParameter]
        string $city,
        #[SensitiveParameter]
        string $country,
    ): self {
        $result = self::check(ValidationResult::valid(), 'firstLine', 'address_line', $firstLine, self::MAX_LINE_LENGTH);
        if ($secondLine !== null) {
            $result = self::check($result, 'secondLine', 'address_line', $secondLine, self::MAX_LINE_LENGTH);
        }
        $result = self::check($result, 'postalCode', 'postal_code', $postalCode, self::MAX_POSTAL_CODE_LENGTH);
        $result = self::check($result, 'city', 'city', $city, self::MAX_CITY_LENGTH);
        $result = self::check($result, 'country', 'country', $country, self::MAX_COUNTRY_LENGTH);
        $result->throwIfInvalid();

        return new self($firstLine, $secondLine, $postalCode, $city, $country);
    }

    /**
     * `adresse1`.
     */
    public function firstLine(): string
    {
        return $this->firstLine;
    }

    /**
     * `adresse2`, null when absent.
     */
    public function secondLine(): ?string
    {
        return $this->secondLine;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    /**
     * `ville`.
     */
    public function city(): string
    {
        return $this->city;
    }

    /**
     * `pays`.
     */
    public function country(): string
    {
        return $this->country;
    }

    /**
     * @return array{firstLine: string, secondLine: string|null, postalCode: string, city: string, country: string}
     */
    public function __debugInfo(): array
    {
        return [
            'firstLine' => '***',
            'secondLine' => $this->secondLine === null ? null : '***',
            'postalCode' => '***',
            'city' => '***',
            'country' => '***',
        ];
    }

    private static function check(
        ValidationResult $result,
        string $path,
        string $subject,
        #[SensitiveParameter]
        string $value,
        int $maxLength,
    ): ValidationResult {
        if (trim($value) === '') {
            return $result->with(InvalidValue::empty($subject)->toViolation($path));
        }
        if (mb_strlen($value) > $maxLength) {
            return $result->with(InvalidValue::tooLong($subject, $maxLength)->toViolation($path));
        }

        return $result;
    }
}
