<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;

/**
 * What the contract test classes share (composition, not inheritance): the skips, one shared gateway (so one token), and
 * the helpers that record what the sandbox did without ever echoing a value, a message or a description from URSSAF.
 */
final class SandboxSession
{
    private static ?SandboxGateway $shared = null;

    private function __construct(
        public readonly SandboxGateway $sandbox,
        private readonly string $test,
    ) {}

    /**
     * Skips the calling test unless the credentials are set.
     */
    public static function readOnly(string $test): self
    {
        return self::start($test);
    }

    /**
     * Skips the calling test unless the credentials are set and URSSAF_SANDBOX_WRITE=1 (these tests write sandbox state
     * and e-mail the auto-entrepreneur).
     */
    public static function writing(string $test): self
    {
        $session = self::start($test);
        if (!SandboxGateway::writesAllowed()) {
            TestCase::markTestSkipped(
                'Writes sandbox state and e-mails the auto-entrepreneur: set URSSAF_SANDBOX_WRITE=1 to run (030, 031, 050, 060, 070, 071; see `make test-contract`).',
            );
        }

        return $session;
    }

    public function requireSiret(): Siret
    {
        $siret = SandboxGateway::siret();
        if (!$siret instanceof Siret) {
            TestCase::markTestSkipped('Set URSSAF_SANDBOX_SIRET (sandbox test account) to run this test (see `make test-contract`).');
        }

        return $siret;
    }

    public function requireAccount(): AccountReference
    {
        $birthDate = SandboxGateway::birthDate();
        if (!$birthDate instanceof CalendarDate) {
            TestCase::markTestSkipped('Set URSSAF_SANDBOX_SIRET and URSSAF_SANDBOX_BIRTH_DATE to run this test (see `make test-contract`).');
        }

        return AccountReference::of($this->requireSiret(), $birthDate);
    }

    /**
     * A non-failing note: the line goes to the standard error stream (PHPUnit does not capture it) and to
     * var/contract/observations.log. Never put a value of the API in it.
     */
    public function note(string $point, string $observation): void
    {
        $line = \sprintf("[contract] %s | %s | %s\n", $point, $this->test, $observation);
        fwrite(\STDERR, $line);

        $directory = \dirname(__DIR__, 2) . '/var/contract';
        if (is_dir($directory) || mkdir($directory, 0o775, true) || is_dir($directory)) {
            file_put_contents($directory . '/observations.log', $line, \FILE_APPEND);
        }
    }

    /**
     * A catalogued rejection is an accepted outcome: asserts it carries known codes and notes its HTTP status, kind and codes.
     */
    public function noteRejection(string $point, UrssafRequestRejected $rejected): void
    {
        Assert::assertGreaterThan(0, $rejected->errors()->count(), 'A rejection carries at least one error.');
        $this->assertCatalogued($rejected->errors());
        $this->note($point, \sprintf('rejected: HTTP %d, kind %s, codes [%s]', $rejected->httpStatus(), $rejected->kind()->value, implode(',', $rejected->errors()->codes())));
    }

    /**
     * Every code of an error answer is in the {@see ApiErrorCode} catalogue; a new one is a finding to catalogue.
     */
    public function assertCatalogued(ApiErrors $errors): void
    {
        foreach ($errors->codes() as $code) {
            Assert::assertNotNull(
                ApiErrorCode::fromCode($code),
                \sprintf('Error code "%s" is not in the ApiErrorCode catalogue.', $code),
            );
        }
    }

    /**
     * The period, sent as integers, was not the reason of a rejection (the messages are never echoed).
     */
    public function assertPeriodNotRejected(ApiErrors $errors, Exchange $sent): void
    {
        Assert::assertSame('int', $sent->requestPeriodType('annee'), 'The year is sent as an integer.');
        if ($errors->hasCode(ApiErrorCode::InvalidParameter->value)) {
            $this->note('period-encoding', 'PARAM_INVALIDE answered while the period was sent as integers: read the message in the sandbox portal');
        }
    }

    private static function start(string $test): self
    {
        self::$shared ??= SandboxGateway::fromEnvironment();
        if (!self::$shared instanceof SandboxGateway) {
            TestCase::markTestSkipped(
                'URSSAF sandbox contract tests are opt-in: set URSSAF_SANDBOX_CLIENT_ID and URSSAF_SANDBOX_CLIENT_SECRET (see `make test-contract` and README, "Contract tests").',
            );
        }

        return new self(self::$shared, $test);
    }
}
