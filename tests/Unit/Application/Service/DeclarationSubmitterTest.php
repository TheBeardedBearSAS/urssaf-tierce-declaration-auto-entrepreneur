<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\SubmissionErrorView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclareAnswers;
use Throwable;

/**
 * Claim, call and settlement of one submission (ADR 0003, ADR 0009), by answer of the gateway.
 *
 * @mago-expect lint:too-many-methods
 */
final class DeclarationSubmitterTest extends TestCase
{
    public function testALostClaimSendsNothingAndDescribesTheRecordTheWinnerHolds(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->records->refuseClaim(DeclarationRecordId::fromString('record-1'));

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('pending', $submission->declaration->status->state);
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    public function testAnAcceptedAnswerIsRecordedAsDeclaredInOneTransaction(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('declared', $submission->declaration->status->state);
        static::assertSame('12345678', $submission->declaration->status->result?->certificat);
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $submission->declaration->status->result->idDeclaration);
        static::assertSame(
            [
                'claim:record-1',
                'call:050',
                'begin',
                'save:record-1',
                'append:urssaf.tdae.declaration.declared',
                'commit',
                'publish:DeclarationDeclared',
            ],
            $world->journal->entries(),
        );
        static::assertEquals([DeclarationWorld::declaration()], $world->gateway->declarations());
        static::assertTrue(DeclarationWorld::siret()->equals($world->gateway->sirets()[0]));
    }

    /**
     * @return iterable<string, array{string, ItemOutcome, string}>
     */
    public static function returnedAnswers(): iterable
    {
        yield 'a 400' => ['rejected', ItemOutcome::Rejected, 'rejected'];
        yield 'a 5xx' => ['unknown', ItemOutcome::OutcomeUnknown, 'outcome_unknown'];
        yield 'a transport error' => ['transport', ItemOutcome::OutcomeUnknown, 'outcome_unknown'];
        yield 'a 403' => ['forbidden', ItemOutcome::InvalidCredentials, 'invalid_credentials'];
        yield 'a 401' => ['credentials', ItemOutcome::InvalidCredentials, 'invalid_credentials'];
    }

    #[DataProvider('returnedAnswers')]
    public function testAFinalAnswerIsSettledAndReturned(string $answer, ItemOutcome $outcome, string $state): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure($answer));

        $submission = $this->submit($world);

        static::assertSame($outcome, $submission->outcome);
        static::assertSame($state, $submission->declaration->status->state);
        static::assertSame($state, $this->stored($world)->state()->value);
        static::assertSame(1, $this->stored($world)->attempts());
    }

    public function testARejectionStoresTheUrssafErrorsWithTheirKind(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure('rejected'));

        $submission = $this->submit($world);

        static::assertSame(
            [['ER_API_050_0010', 'period_not_open', 'Periode non ouverte', 'Detail']],
            $this->errorsOf($submission->declaration->status->errors),
        );
        static::assertSame(
            [
                'claim:record-1',
                'call:050',
                'begin',
                'save:record-1',
                'append:urssaf.tdae.declaration.rejected',
                'commit',
                'publish:DeclarationRejected',
            ],
            $world->journal->entries(),
        );
    }

    public function testARejectionWithoutDetailsStoresASingleGenericError(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, UrssafRequestRejected::withErrors(Operation::Declare, 400, ApiErrors::none(), RejectionKind::Unknown));

        $submission = $this->submit($world);

        static::assertSame(
            [['urssaf.rejected', 'unknown', 'URSSAF refused the call without error details.', null]],
            $this->errorsOf($submission->declaration->status->errors),
        );
    }

    public function testAnUnknownOutcomeKeepsTheReasonAndIsNeverResent(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure('unknown'));

        $submission = $this->submit($world);

        static::assertSame('may_have_reached_urssaf', $submission->declaration->status->outcomeUnknownReason);
        static::assertSame([['urssaf.outcome_unknown', 'technical', 'server_error', null]], $this->errorsOf($submission->declaration->status->errors));
        static::assertSame(['050'], $world->gateway->calls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function retryableAnswers(): iterable
    {
        yield 'a 503, which may have reached URSSAF (resendable on 050)' => ['unavailable'];
        yield 'a 429' => ['throttled'];
        yield 'the token endpoint unavailable' => ['token'];
        yield 'the local rate limit' => ['rate_limit'];
    }

    #[DataProvider('retryableAnswers')]
    public function testARetryableFailureRequeuesTheRecordAndThrowsAfterTheCommit(string $answer): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $failure = DeclareAnswers::failure($answer);
        $world->gateway->queue(Operation::Declare, $failure);

        $thrown = $this->submitAndCatch($world);

        static::assertInstanceOf(RetryableUrssafFailure::class, $thrown);
        static::assertSame($failure, $thrown->getPrevious());
        $record = $this->stored($world);
        static::assertSame(DeclarationState::Pending, $record->state());
        static::assertSame(1, $record->attempts());
        static::assertSame(
            ['claim:record-1', 'call:050', 'begin', 'save:record-1', 'append:', 'commit', 'publish:DeclarationSubmissionFailed', 'publish:DeclarationRequeued'],
            $world->journal->entries(),
        );
    }

    public function testTheRetryableFailureCarriesTheDelayOfTheOriginalFailure(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure('unavailable'));

        $thrown = $this->submitAndCatch($world);

        $previous = $thrown?->getPrevious();
        static::assertInstanceOf(UrssafUnavailable::class, $previous);
        static::assertSame(30, $previous->retryAfterSeconds());
    }

    #[DataProvider('retryableAnswers')]
    public function testARetryableFailureStoresItsClassAsTheErrorWhenUrssafGaveNoDetail(string $answer): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $failure = DeclareAnswers::failure($answer);
        $world->gateway->queue(Operation::Declare, $failure);

        $this->submitAndCatch($world);

        static::assertSame([['urssaf.unavailable', 'technical', $failure::class, null]], $this->errorsOf($this->stored($world)->errors()));
    }

    public function testAnExhaustedRecordIsLeftInErrorAndReturned(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(1));
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure('unavailable'));

        $submission = $this->submit($world);

        static::assertSame(ItemOutcome::Exhausted, $submission->outcome);
        static::assertSame('error', $submission->declaration->status->state);
        static::assertSame([['urssaf.unavailable', 'technical', UrssafUnavailable::class, null]], $this->errorsOf($submission->declaration->status->errors));
        static::assertSame(['claim:record-1', 'call:050', 'begin', 'save:record-1', 'append:', 'commit', 'publish:DeclarationSubmissionFailed'], $world->journal->entries());
    }

    public function testTheLastAttemptIsExhaustedWhileTheFirstIsRequeued(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(2));
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure('throttled'), DeclareAnswers::failure('throttled'));

        $first = $this->submitAndCatch($world);
        $second = $this->submit($world);

        static::assertInstanceOf(RetryableUrssafFailure::class, $first);
        static::assertSame(ItemOutcome::Exhausted, $second->outcome);
        static::assertSame(2, $this->stored($world)->attempts());
        static::assertSame(DeclarationState::Error, $this->stored($world)->state());
    }

    public function testALocalRefusalIsRequeuedAndRethrownUnchanged(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $failure = DeclareAnswers::failure('not_found');
        $world->gateway->queue(Operation::Declare, $failure);

        $thrown = $this->submitAndCatch($world);

        static::assertSame($failure, $thrown);
        static::assertSame(DeclarationState::Pending, $this->stored($world)->state());
        static::assertSame(1, $this->stored($world)->attempts());
    }

    public function testAnExhaustedLocalRefusalIsLeftInErrorAndRethrown(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(1));
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $failure = DeclareAnswers::failure('not_found');
        $world->gateway->queue(Operation::Declare, $failure);

        $thrown = $this->submitAndCatch($world);

        static::assertSame($failure, $thrown);
        static::assertSame(DeclarationState::Error, $this->stored($world)->state());
        static::assertSame([['credentials.not_found', 'unknown', RecordNotFound::class, null]], $this->errorsOf($this->stored($world)->errors()));
    }

    public function testAnUnexpectedDomainFailureIsAnUnknownOutcomeRethrownUnchanged(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $failure = DeclareAnswers::failure('domain');
        $world->gateway->queue(Operation::Declare, $failure);

        $thrown = $this->submitAndCatch($world);

        static::assertSame($failure, $thrown);
        $record = $this->stored($world);
        static::assertSame(DeclarationState::OutcomeUnknown, $record->state());
        static::assertSame('may_have_reached_urssaf', $record->outcomeUnknownReason()?->value);
        static::assertSame([['gateway.broken', 'unknown', $failure::class, null]], $this->errorsOf($record->errors()));
    }

    public function testAForeignFailureIsAnUnknownOutcomeAndItsMessageIsNeverStored(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $failure = DeclareAnswers::failure('foreign');
        $world->gateway->queue(Operation::Declare, $failure);

        $thrown = $this->submitAndCatch($world);

        static::assertSame($failure, $thrown);
        $errors = $this->stored($world)->errors();
        static::assertSame([['urssaf.unexpected_failure', 'unknown', RuntimeException::class, null]], $this->errorsOf($errors));
        static::assertStringNotContainsString('foreign gateway failure', serialize($errors));
    }

    private function submit(DeclarationWorld $world): DeclarationSubmission
    {
        return $world->submitter()->submit(
            DeclarationRecordId::fromString('record-1'),
            DeclarationWorld::tenant(),
            DeclarationWorld::siret(),
            DeclarationWorld::declaration(),
        );
    }

    private function submitAndCatch(DeclarationWorld $world): ?Throwable
    {
        try {
            $this->submit($world);
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function stored(DeclarationWorld $world): DeclarationRecord
    {
        return $world->records->get(DeclarationRecordId::fromString('record-1'));
    }

    /**
     * @param list<SubmissionError|SubmissionErrorView> $errors
     *
     * @return list<array{string, string, ?string, ?string}>
     */
    private function errorsOf(array $errors): array
    {
        return array_map(
            static fn(SubmissionError|SubmissionErrorView $error): array => $error instanceof SubmissionError
                ? [$error->code(), $error->kind(), $error->message(), $error->description()]
                : [$error->code, $error->kind, $error->message, $error->description],
            $errors,
        );
    }
}
