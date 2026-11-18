<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Output\BufferedOutput;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\AccountErasureRefused;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\DeclarationRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentAlreadyRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandReports;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use Throwable;

/**
 * One test per rule of the execution guard and of the renderers, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final class CommandReportsTest extends TestCase
{
    public function testTheResultOfTheActionIsReturned(): void
    {
        $output = new BufferedOutput();

        static::assertSame(0, CommandReports::guard($output, static fn(): int => 0));
        static::assertSame(1, CommandReports::guard($output, static fn(): int => 1));
        static::assertSame('', $output->fetch());
    }

    public function testAnInvalidOptionIsAnInvalidUsage(): void
    {
        $output = new BufferedOutput();

        $status = CommandReports::guard($output, static fn(): int => throw new InvalidOptionException('The --limit option must be a positive integer.'));

        static::assertSame(2, $status);
        static::assertSame("The --limit option must be a positive integer.\n", $output->fetch());
    }

    public function testAValidationFailureIsAnInvalidUsageWithOneLinePerError(): void
    {
        $output = new BufferedOutput();
        $failure = ValidationFailed::withResult(ValidationResult::of(
            Violation::error('turnover.sales', 'euros.negative', 'Invalid sales.'),
            Violation::warning('turnover', 'turnover.warning', 'A warning.'),
            Violation::error('period.month', 'period.month_out_of_range', 'Out of range.'),
        ));

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(2, $status);
        static::assertSame("turnover.sales: Invalid sales. (euros.negative)\nperiod.month: Out of range. (period.month_out_of_range)\nInvalid input: 2 error(s).\n", $output->fetch());
    }

    public function testAnInvalidValueIsAnInvalidUsage(): void
    {
        $output = new BufferedOutput();

        $status = CommandReports::guard($output, static fn(): int => throw InvalidValue::because('siret.invalid_format', 'Invalid siret.'));

        static::assertSame(2, $status);
        static::assertSame("Invalid siret. (siret.invalid_format)\n", $output->fetch());
    }

    public function testARefusedMandateIsAnInvalidUsageThatSuggestsTheImport(): void
    {
        $output = new BufferedOutput();
        $failure = MandateRequired::forOperation(Operation::Declare);

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(2, $status);
        static::assertSame($failure->getMessage() . "\nUse --skip-mandate-check, or record the mandate without any call with urssaf:tdae:mandate:import.\n", $output->fetch());
    }

    #[DataProvider('invalidUsages')]
    public function testAMissingOrConflictingRecordIsAnInvalidUsage(Throwable $failure): void
    {
        $output = new BufferedOutput();

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(2, $status);
        static::assertSame($failure->getMessage() . "\n", $output->fetch());
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function invalidUsages(): array
    {
        return [
            'record not found' => [RecordNotFound::withIdentifier('declaration_record', 'x')],
            'payment already recorded' => [PaymentAlreadyRecorded::withReference(PaymentWorld::reference(), PaymentRecordId::fromString('record-1'))],
        ];
    }

    #[DataProvider('failures')]
    public function testAnyOtherPackageFailureIsAFailureWithItsMessage(Throwable $failure, string $message): void
    {
        $output = new BufferedOutput();

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(1, $status);
        static::assertSame($message . "\n", $output->fetch());
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function failures(): iterable
    {
        yield 'authentication failed' => [UrssafAuthenticationFailed::withStatus(401), UrssafAuthenticationFailed::withStatus(401)->getMessage()];
        yield 'invariant' => [InvariantViolation::because('workflow_policy.invalid_limit', 'A limit.'), 'A limit.'];
        yield 'submission in flight' => [AccountErasureRefused::submissionInFlight(null, 2), AccountErasureRefused::submissionInFlight(null, 2)->getMessage()];
    }

    public function testAForeignFailureIsNotCaught(): void
    {
        $this->expectException(RuntimeException::class);

        CommandReports::guard(new BufferedOutput(), static fn(): int => throw new RuntimeException('Bug.'));
    }

    #[DataProvider('erasureRefusals')]
    public function testARefusedErasureNamesTheOptInThatWouldLetItThrough(AccountErasureRefused $failure, string $hint): void
    {
        $output = new BufferedOutput();

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(1, $status);
        static::assertSame($failure->getMessage() . "\n" . $hint . "\n", $output->fetch());
    }

    /**
     * @return array<string, array{AccountErasureRefused, string}>
     */
    public static function erasureRefusals(): array
    {
        $account = AccountId::fromString('account-1');

        return [
            'active mandate' => [AccountErasureRefused::activeMandate($account, 1), 'Cancel the mandate first (urssaf:tdae:mandate:cancel), or pass --allow-active-mandate to erase it locally anyway.'],
            'unknown outcomes' => [AccountErasureRefused::unknownOutcomes($account, 2), 'Resolve the unknown outcomes first (urssaf:tdae:declarations:resolve, urssaf:tdae:payments:resolve), or pass --allow-unknown-outcomes to erase them anyway.'],
        ];
    }

    public function testAnAcceptedNotificationThatWasNotRecordedPointsToTheImport(): void
    {
        $output = new BufferedOutput();
        $failure = MandateRecordingFailed::after(Operation::NotifyMandate, 3, 'mandate.concurrent_modification');

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(1, $status);
        static::assertSame($failure->getMessage() . "\nURSSAF accepted the request: do not run the command again. Record the mandate without any call: urssaf:tdae:mandate:import (same SIRET).\n", $output->fetch());
    }

    public function testAnAcceptedCancellationThatWasNotRecordedPointsToTheRecord(): void
    {
        $output = new BufferedOutput();
        $failure = MandateRecordingFailed::after(Operation::CancelMandate, 3, 'mandate.concurrent_modification');

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(1, $status);
        static::assertSame($failure->getMessage() . "\nURSSAF accepted the request: do not run the command again. Record the cancellation without any call: urssaf:tdae:mandate:record-cancellation (same SIRET).\n", $output->fetch());
    }

    public function testAnAcceptedDeclarationThatWasNotRecordedGivesItsCertificat(): void
    {
        $output = new BufferedOutput();
        $failure = DeclarationRecordingFailed::after(DeclarationRecordId::fromString('record-1'), DeclarationWorld::accepted(), 3, 'declaration_record.concurrent_modification');

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(1, $status);
        static::assertSame(
            $failure->getMessage() . "\nCertificat: 12345678\nidDeclaration: W1dl9W2Oa4R6ab3UlQAqxF3\nURSSAF accepted the declaration: never send it again. Run urssaf:tdae:declarations:reconcile, then urssaf:tdae:declarations:resolve --declared-as=<certificat>.\n",
            $output->fetch(),
        );
    }

    public function testAnAcceptedDeclarationWithoutAnIdentifierStillPointsToTheResolution(): void
    {
        $output = new BufferedOutput();
        $failure = DeclarationRecordingFailed::after(DeclarationRecordId::fromString('record-1'), DeclarationResult::resolved(Certificate::fromString('12345678')), 3, 'declaration_record.concurrent_modification');

        static::assertSame(1, CommandReports::guard($output, static fn(): int => throw $failure));

        $display = $output->fetch();
        static::assertStringContainsString("\nCertificat: 12345678\n", $display);
        static::assertStringNotContainsString('idDeclaration', $display);
    }

    public function testAnAcceptedPaymentThatWasNotRecordedGivesItsCertificat(): void
    {
        $output = new BufferedOutput();
        $failure = PaymentRecordingFailed::after(PaymentRecordId::fromString('record-1'), PaymentWorld::paid(), 3, 'payment_record.concurrent_modification');

        $status = CommandReports::guard($output, static fn(): int => throw $failure);

        static::assertSame(1, $status);
        static::assertSame(
            $failure->getMessage() . "\nCertificat: 12345678\nidTelepaiement: W1dl9W2Oa4R6ab3UlQAqxF2\nURSSAF accepted the payment: never order it again. Run urssaf:tdae:payments:reconcile, then urssaf:tdae:payments:resolve --paid-as=<certificat>.\n",
            $output->fetch(),
        );
    }

    public function testAnAcceptedPaymentWithoutAnIdentifierStillPointsToTheResolution(): void
    {
        $output = new BufferedOutput();
        $failure = PaymentRecordingFailed::after(PaymentRecordId::fromString('record-1'), PaymentResult::resolved(Certificate::fromString('12345678')), 3, 'payment_record.concurrent_modification');

        static::assertSame(1, CommandReports::guard($output, static fn(): int => throw $failure));

        $display = $output->fetch();
        static::assertStringContainsString("\nCertificat: 12345678\n", $display);
        static::assertStringNotContainsString('idTelepaiement', $display);
    }

    public function testARetryableFailureSaysWhenToRetry(): void
    {
        $withDelay = new BufferedOutput();
        $withoutDelay = new BufferedOutput();
        $limited = RetryableUrssafFailure::causedBy(UrssafUnavailable::withStatus(Operation::Declare, 429, 30, ApiErrors::none()));
        $unlimited = RetryableUrssafFailure::causedBy(UrssafUnavailable::withStatus(Operation::Declare, 503, null, ApiErrors::none()));

        static::assertSame(1, CommandReports::guard($withDelay, static fn(): int => throw $limited));
        static::assertSame(1, CommandReports::guard($withoutDelay, static fn(): int => throw $unlimited));

        static::assertSame($limited->getMessage() . "\nNothing was recorded as sent: run the command again in 30 second(s) or more.\n", $withDelay->fetch());
        static::assertSame($unlimited->getMessage() . "\nNothing was recorded as sent: run the command again later.\n", $withoutDelay->fetch());
    }

    public function testARetryableFailureWithoutAnyDelayAsksToRunAgainLater(): void
    {
        $output = new BufferedOutput();
        $failure = new class ('Try later.') extends RuntimeException implements RetryableException {};

        static::assertSame(1, CommandReports::guard($output, static fn(): int => throw $failure));

        static::assertSame("Try later.\nNothing was recorded as sent: run the command again later.\n", $output->fetch());
    }

    public function testALocalRateLimitSaysWhenToRetry(): void
    {
        $output = new BufferedOutput();

        $status = CommandReports::guard($output, static fn(): int => throw RateLimitExceeded::forOperation(Operation::Declare, 5));

        static::assertSame(1, $status);
        static::assertStringEndsWith("run the command again in 5 second(s) or more.\n", $output->fetch());
    }

    public function testAReportIsATableFollowedByTheCountOfEachOutcome(): void
    {
        $output = new BufferedOutput();
        $report = BatchReport::of(
            new BatchItem('record-1', ItemOutcome::Changed),
            new BatchItem('record-2', ItemOutcome::Unchanged, ['a', 'b']),
            new BatchItem('record-3', ItemOutcome::Changed),
        );

        static::assertSame(0, CommandReports::renderReport($output, $report));

        $display = $output->fetch();
        static::assertSame([['Record', 'Outcome', 'Codes'], ['record-1', 'changed', ''], ['record-2', 'unchanged', 'a, b'], ['record-3', 'changed', '']], Output::rows($display));
        static::assertStringEndsWith("3 record(s) processed (changed: 2, unchanged: 1).\n", $display);
    }

    public function testAnEmptyReportHasNoCounts(): void
    {
        $output = new BufferedOutput();

        static::assertSame(0, CommandReports::renderReport($output, BatchReport::of()));

        static::assertStringEndsWith("0 record(s) processed.\n", $output->fetch());
    }

    #[DataProvider('failedOutcomes')]
    public function testAReportWithAFailureExitsWithOne(ItemOutcome $outcome): void
    {
        static::assertSame(1, CommandReports::renderReport(new BufferedOutput(), BatchReport::of(new BatchItem('record-1', ItemOutcome::Changed), new BatchItem('record-2', $outcome))));
    }

    /**
     * @return iterable<string, array{ItemOutcome}>
     */
    public static function failedOutcomes(): iterable
    {
        foreach (ItemOutcome::cases() as $outcome) {
            if (\in_array($outcome, [ItemOutcome::Declared, ItemOutcome::Paid, ItemOutcome::Replayed, ItemOutcome::Changed, ItemOutcome::Unchanged], true)) {
                continue;
            }

            yield $outcome->value => [$outcome];
        }
    }

    #[DataProvider('successfulOutcomes')]
    public function testAReportWithoutAFailureExitsWithZero(ItemOutcome $outcome): void
    {
        static::assertSame(0, CommandReports::renderReport(new BufferedOutput(), BatchReport::of(new BatchItem('record-1', $outcome))));
    }

    /**
     * @return iterable<string, array{ItemOutcome}>
     */
    public static function successfulOutcomes(): iterable
    {
        foreach ([ItemOutcome::Declared, ItemOutcome::Paid, ItemOutcome::Replayed, ItemOutcome::Changed, ItemOutcome::Unchanged] as $outcome) {
            yield $outcome->value => [$outcome];
        }
    }

    public function testBusyIsNotAFailure(): void
    {
        $output = new BufferedOutput();

        static::assertSame(0, CommandReports::renderBusy($output, 'urssaf_tdae.outbox.relay.all'));

        static::assertSame("Skipped: \"urssaf_tdae.outbox.relay.all\" is held by another process.\n", $output->fetch());
    }

    public function testAPlanListsTheRecordsAndSaysNothingWasCalled(): void
    {
        $output = new BufferedOutput();

        static::assertSame(0, CommandReports::renderPlan($output, [['tenant-1', 'record-1'], ['tenant-2', 'record-2']]));

        $display = $output->fetch();
        static::assertSame([['Tenant', 'Record'], ['tenant-1', 'record-1'], ['tenant-2', 'record-2']], Output::rows($display));
        static::assertStringEndsWith("Dry run: 2 record(s) would be processed, URSSAF was not called.\n", $display);
    }

    public function testAViewIsATwoColumnTable(): void
    {
        $output = new BufferedOutput();

        CommandReports::renderFields($output, [['Account id', 'a-1'], ['Stage', 'registered']]);

        static::assertSame([['Field', 'Value'], ['Account id', 'a-1'], ['Stage', 'registered']], Output::rows($output->fetch()));
    }

    public function testTheValuesAreFormattedForTheOperator(): void
    {
        static::assertSame('yes', CommandReports::yesNo(true));
        static::assertSame('no', CommandReports::yesNo(false));
        static::assertSame('-', CommandReports::yesNo(null));
        static::assertSame('value', CommandReports::orDash('value'));
        static::assertSame('', CommandReports::orDash(''));
        static::assertSame('-', CommandReports::orDash(null));
    }
}
