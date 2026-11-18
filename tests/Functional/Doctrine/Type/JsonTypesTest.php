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

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\DeclarationResultType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\PaymentResultType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Type\SubmissionErrorsType;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;

/**
 * Conversion scenarios of the JSON column types: one method per scenario. Errors never quote a stored document nor a
 * foreign PHP value (the documents hold personal data, ADR 0010).
 *
 * @mago-expect lint:too-many-methods
 */
final class JsonTypesTest extends TestCase
{
    private const ERRORS_TYPE = 'list<' . SubmissionError::class . '>';

    private const AMOUNTS = '{"total":1000,"sales":100,"furnishedRental":200,"commercialServices":300,"otherServices":400,"cipav":500,"vocationalTraining":600,"liberatory":{"sales":11,"furnishedRental":12,"commercialServices":13,"otherServices":14,"cipav":15},"chamberFees":{"cmaSales":21,"cmaServices":22,"cciSales":23,"cciServices":24},"acreRegularisation":-5,"deduction":0}';

    private const MINIMAL_AMOUNTS = '{"total":0,"sales":null,"furnishedRental":null,"commercialServices":null,"otherServices":null,"cipav":null,"vocationalTraining":null,"liberatory":{"sales":null,"furnishedRental":null,"commercialServices":null,"otherServices":null,"cipav":null},"chamberFees":{"cmaSales":null,"cmaServices":null,"cciSales":null,"cciServices":null},"acreRegularisation":null,"deduction":null}';

    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        DoctrineIntegration::registerTypes();
        $this->platform = new SQLitePlatform();
    }

    /**
     * @return iterable<string, array{string}> case names of {@see self::document()}
     */
    public static function documents(): iterable
    {
        yield 'errors with and without message and description' => ['errors'];
        yield 'no error' => ['no-errors'];
        yield 'full declaration result' => ['declaration-full'];
        yield 'declaration result with the amounts absent from the answer' => ['declaration-minimal'];
        yield 'declaration resolved by an operator' => ['declaration-resolved'];
        yield 'full payment result' => ['payment-full'];
        yield 'payment result of a quarter without identifier' => ['payment-quarter'];
        yield 'payment resolved by an operator' => ['payment-resolved'];
    }

    /**
     * @return array{string, object|list<SubmissionError>, string, string} type name, value, expected JSON, description of the PHP type
     *
     * @throws InvalidValue
     */
    private static function document(string $case): array
    {
        $certificate = Certificate::fromString('12345678');

        return match ($case) {
            'errors' => [
                SubmissionErrorsType::NAME,
                [
                    SubmissionError::of('ER_API_050_0070', 'unknown_mandate', 'First message.', 'First description.'),
                    SubmissionError::of('ER_API_050_0080', 'technical', null, null),
                ],
                '[{"code":"ER_API_050_0070","kind":"unknown_mandate","message":"First message.","description":"First description."},{"code":"ER_API_050_0080","kind":"technical","message":null,"description":null}]',
                self::ERRORS_TYPE,
            ],
            'no-errors' => [SubmissionErrorsType::NAME, [], '[]', self::ERRORS_TYPE],
            'declaration-full' => [
                DeclarationResultType::NAME,
                DeclarationResult::of($certificate, 'DECL-0001', CalendarDate::fromString('2026-11-30'), self::fullAmounts()),
                '{"certificate":"12345678","declarationId":"DECL-0001","dueDate":"2026-11-30","amounts":' . self::AMOUNTS . '}',
                DeclarationResult::class,
            ],
            'declaration-minimal' => [
                DeclarationResultType::NAME,
                DeclarationResult::of(
                    $certificate,
                    'DECL-0002',
                    CalendarDate::fromString('2026-12-31'),
                    ContributionAmounts::of(Euros::of(0), null, null, null, null, null, null, LiberatoryContributions::none(), ChamberFees::none(), null, null),
                ),
                '{"certificate":"12345678","declarationId":"DECL-0002","dueDate":"2026-12-31","amounts":' . self::MINIMAL_AMOUNTS . '}',
                DeclarationResult::class,
            ],
            'declaration-resolved' => [
                DeclarationResultType::NAME,
                DeclarationResult::resolved($certificate),
                '{"certificate":"12345678","declarationId":null,"dueDate":null,"amounts":null}',
                DeclarationResult::class,
            ],
            'payment-full' => [
                PaymentResultType::NAME,
                PaymentResult::of($certificate, 'PAY-0001', CalendarDate::fromString('2026-11-20'), MonthlyPeriod::of(2026, 10)),
                '{"certificate":"12345678","paymentId":"PAY-0001","dueDate":"2026-11-20","period":{"periodicity":"M","year":2026,"number":10}}',
                PaymentResult::class,
            ],
            'payment-quarter' => [
                PaymentResultType::NAME,
                PaymentResult::of($certificate, null, CalendarDate::fromString('2026-10-31'), QuarterlyPeriod::of(2026, 3)),
                '{"certificate":"12345678","paymentId":null,"dueDate":"2026-10-31","period":{"periodicity":"T","year":2026,"number":3}}',
                PaymentResult::class,
            ],
            default => [
                PaymentResultType::NAME,
                PaymentResult::resolved($certificate),
                '{"certificate":"12345678","paymentId":null,"dueDate":null,"period":null}',
                PaymentResult::class,
            ],
        };
    }

    #[DataProvider('documents')]
    public function testItStoresAnExplicitDocumentAndRebuildsTheValue(string $case): void
    {
        [$name, $value, $json] = self::document($case);
        $type = Type::getType($name);

        static::assertSame($json, $type->convertToDatabaseValue($value, $this->platform));
        static::assertEquals($value, $type->convertToPHPValue($json, $this->platform));
    }

    public function testACertificateStoredAsUrssafAnsweredItSurvivesTheRoundTrip(): void
    {
        $lenient = Certificate::fromResponse('A-12345');
        $declaration = DeclarationResult::resolved($lenient);
        $payment = PaymentResult::resolved($lenient);

        foreach ([[DeclarationResultType::NAME, $declaration], [PaymentResultType::NAME, $payment]] as [$name, $result]) {
            $type = Type::getType($name);
            // @mago-expect analysis:mixed-assignment The type converts to an untyped database value; it is checked to be a string below.
            $json = $type->convertToDatabaseValue($result, $this->platform);

            static::assertIsString($json);
            static::assertStringContainsString('"certificate":"A-12345"', $json);
            static::assertEquals($result, $type->convertToPHPValue($json, $this->platform));
        }
    }

    #[DataProvider('documents')]
    public function testNullStaysNull(string $case): void
    {
        [$name] = self::document($case);
        $type = Type::getType($name);

        static::assertNull($type->convertToDatabaseValue(null, $this->platform));
        static::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    #[DataProvider('documents')]
    public function testItRefusesAForeignPhpValueWithoutQuotingIt(string $case): void
    {
        [$name, , , $expected] = self::document($case);

        try {
            Type::getType($name)->convertToDatabaseValue('SECRET-foreign', $this->platform);
            static::fail('Expected a conversion failure.');
        } catch (ConversionException $exception) {
            static::assertStringEndsWith('types: ' . $expected . ', null.', $exception->getMessage());
            static::assertStringContainsString('***', $exception->getMessage());
            static::assertStringNotContainsString('SECRET', $exception->getMessage());
        }
    }

    #[DataProvider('documents')]
    public function testItRefusesAStoredValueThatIsNotValidJsonObjectOrArray(string $case): void
    {
        [$name] = self::document($case);
        $messages = [];

        foreach ([12, '{not json', '12', '"text"'] as $stored) {
            try {
                Type::getType($name)->convertToPHPValue($stored, $this->platform);
                static::fail('Expected a conversion failure.');
            } catch (ConversionException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        static::assertStringEndsWith('types: string, null.', $messages[0]);
        static::assertStringContainsString('Expected format "JSON"', $messages[1]);
        static::assertStringContainsString('Expected format "JSON object or array"', $messages[2]);
        static::assertStringContainsString('Expected format "JSON object or array"', $messages[3]);
        static::assertStringNotContainsString('{not json', $messages[1]);
        static::assertStringContainsString('database value "***"', $messages[1]);
        static::assertStringContainsString('database value "***"', $messages[2]);
        static::assertStringContainsString('database value "***"', $messages[3]);
    }

    public function testAJsonDocumentIsDeclaredAsAJsonColumn(): void
    {
        foreach ([SubmissionErrorsType::NAME, DeclarationResultType::NAME, PaymentResultType::NAME] as $name) {
            static::assertSame(
                $this->platform->getJsonTypeDeclarationSQL([]),
                Type::getType($name)->getSQLDeclaration([], $this->platform),
            );
        }
    }

    public function testItRefusesAListThatIsNotAListOfSubmissionErrors(): void
    {
        $type = Type::getType(SubmissionErrorsType::NAME);
        $error = SubmissionError::of('ERR_A', 'kind', 'SECRET-message', 'SECRET-description');

        foreach ([['a' => $error], [$error, 'SECRET-text'], [1 => $error]] as $value) {
            try {
                $type->convertToDatabaseValue($value, $this->platform);
                static::fail('Expected a conversion failure.');
            } catch (ConversionException $exception) {
                static::assertStringEndsWith('types: ' . self::ERRORS_TYPE . ', null.', $exception->getMessage());
                static::assertStringNotContainsString('SECRET', $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}> type name, stored document the domain refuses
     */
    public static function refusedDocuments(): iterable
    {
        $declaration = static fn(string $id, string $due, string $amounts): string => '{"certificate":"12345678","declarationId":' . $id . ',"dueDate":' . $due . ',"amounts":' . $amounts . '}';
        $payment = static fn(string $id, string $due, string $period): string => '{"certificate":"12345678","paymentId":' . $id . ',"dueDate":' . $due . ',"period":' . $period . '}';
        $period = '{"periodicity":"M","year":2026,"number":10}';

        yield 'errors, empty code' => [SubmissionErrorsType::NAME, '[{"code":"","kind":"k","message":"SECRET-message","description":"SECRET-description"}]'];
        yield 'errors, empty kind' => [SubmissionErrorsType::NAME, '[{"code":"ERR","kind":" ","message":"SECRET-message","description":null}]'];
        yield 'declaration, empty certificate' => [DeclarationResultType::NAME, '{"certificate":" ","declarationId":null,"dueDate":null,"amounts":null}'];
        yield 'declaration, identifier alone' => [DeclarationResultType::NAME, $declaration('"SECRET-id"', 'null', 'null')];
        yield 'declaration, due date alone' => [DeclarationResultType::NAME, $declaration('null', '"2026-11-30"', 'null')];
        yield 'declaration, amounts alone' => [DeclarationResultType::NAME, $declaration('null', 'null', self::AMOUNTS)];
        yield 'declaration, no identifier' => [DeclarationResultType::NAME, $declaration('null', '"2026-11-30"', self::AMOUNTS)];
        yield 'declaration, no due date' => [DeclarationResultType::NAME, $declaration('"SECRET-id"', 'null', self::AMOUNTS)];
        yield 'declaration, no amounts' => [DeclarationResultType::NAME, $declaration('"SECRET-id"', '"2026-11-30"', 'null')];
        yield 'declaration, invalid due date' => [DeclarationResultType::NAME, $declaration('"SECRET-id"', '"2026-13-45"', self::AMOUNTS)];
        yield 'declaration, amount out of range' => [DeclarationResultType::NAME, $declaration('"SECRET-id"', '"2026-11-30"', str_replace('"total":1000', '"total":2147483648', self::AMOUNTS))];
        yield 'declaration, nested amount out of range' => [DeclarationResultType::NAME, $declaration('"SECRET-id"', '"2026-11-30"', str_replace('"cipav":15', '"cipav":-2147483649', self::AMOUNTS))];
        yield 'payment, empty certificate' => [PaymentResultType::NAME, '{"certificate":"","paymentId":null,"dueDate":null,"period":null}'];
        yield 'payment, identifier alone' => [PaymentResultType::NAME, $payment('"SECRET-id"', 'null', 'null')];
        yield 'payment, due date alone' => [PaymentResultType::NAME, $payment('null', '"2026-11-20"', 'null')];
        yield 'payment, period alone' => [PaymentResultType::NAME, $payment('null', 'null', $period)];
        yield 'payment, identifier and period without due date' => [PaymentResultType::NAME, $payment('"SECRET-id"', 'null', $period)];
        yield 'payment, unknown periodicity' => [PaymentResultType::NAME, $payment('null', '"2026-11-20"', '{"periodicity":"X","year":2026,"number":10}')];
        yield 'payment, month out of range' => [PaymentResultType::NAME, $payment('null', '"2026-11-20"', '{"periodicity":"M","year":2026,"number":13}')];
        yield 'payment, quarter out of range' => [PaymentResultType::NAME, $payment('null', '"2026-11-20"', '{"periodicity":"T","year":2026,"number":5}')];
        yield 'payment, invalid due date' => [PaymentResultType::NAME, $payment('null', '"SECRET-date"', $period)];
    }

    #[DataProvider('refusedDocuments')]
    public function testStoredContentThatTheDomainRefusesIsReportedWithoutEchoingIt(string $name, string $stored): void
    {
        try {
            Type::getType($name)->convertToPHPValue($stored, $this->platform);
            static::fail('Expected a conversion failure.');
        } catch (ConversionException $exception) {
            static::assertInstanceOf(InvalidValue::class, $exception->getPrevious());
            static::assertStringContainsString('database value "***"', $exception->getMessage());
            static::assertStringNotContainsString('SECRET', $exception->getMessage());
            static::assertStringNotContainsString('2026-13-45', $exception->getMessage());
        }
    }

    private static function fullAmounts(): ContributionAmounts
    {
        return ContributionAmounts::of(
            Euros::of(1_000),
            Euros::of(100),
            Euros::of(200),
            Euros::of(300),
            Euros::of(400),
            Euros::of(500),
            Euros::of(600),
            LiberatoryContributions::of(Euros::of(11), Euros::of(12), Euros::of(13), Euros::of(14), Euros::of(15)),
            ChamberFees::of(Euros::of(21), Euros::of(22), Euros::of(23), Euros::of(24)),
            Euros::of(-5),
            Euros::of(0),
        );
    }
}
