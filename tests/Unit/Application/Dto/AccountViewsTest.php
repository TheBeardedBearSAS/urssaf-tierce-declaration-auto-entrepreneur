<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Dto;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountErasure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\MandateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\SubmissionErrorView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

final class AccountViewsTest extends TestCase
{
    public function testAnAccountViewHoldsPrimitivesAndNeverTheBirthDate(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount('account-1', AccountWorld::BIRTH_DATE, false, Periodicity::Quarterly);

        $view = AccountView::fromAccount($account, null);

        static::assertSame('account-1', $view->accountId);
        static::assertSame('tenant-1', $view->tenantId);
        static::assertSame(AccountWorld::SIRET, $view->siret);
        static::assertTrue($view->hasBirthDate);
        static::assertFalse($view->mandatePresent);
        static::assertSame('T', $view->periodicity);
        static::assertSame('2025-03-01', $view->affiliationDate);
        static::assertFalse($view->acreExemption);
        static::assertNull($view->mandate);
        static::assertSame('2026-11-01T08:00:00Z', $view->lastSyncedAt);
        static::assertSame('2026-11-01T08:00:00Z', $view->createdAt);
        static::assertSame('2026-11-01T08:00:00Z', $view->updatedAt);
        static::assertStringNotContainsString('1975', serialize($view));
        static::assertSame(['hasBirthDate'], array_values(array_filter(array_keys(get_object_vars($view)), static fn(string $name): bool => str_contains(strtolower($name), 'birth'))));
    }

    public function testAnAccountViewWithoutBirthDateOrAffiliationDate(): void
    {
        $account = AeAccount::track(
            AccountId::fromString('account-2'),
            AccountWorld::tenant(),
            AccountWorld::siret(),
            AccountStatus::of(true, Periodicity::Monthly, null, null),
            null,
            Instant::fromIso8601('2026-11-01T08:00:00Z'),
        );

        $view = AccountView::fromAccount($account, null);

        static::assertFalse($view->hasBirthDate);
        static::assertTrue($view->mandatePresent);
        static::assertSame('M', $view->periodicity);
        static::assertNull($view->affiliationDate);
        static::assertNull($view->acreExemption);
    }

    public function testAnAccountViewHoldsTheShownMandate(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $mandate = $world->activeMandateOf($account);

        $view = AccountView::fromAccount($account, $mandate);

        static::assertInstanceOf(MandateView::class, $view->mandate);
        static::assertSame('mandate-1', $view->mandate->mandateId);
    }

    public function testDumpingAnAccountViewMasksTheSiretAndDropsTheAffiliationDate(): void
    {
        $world = new AccountWorld();
        $view = AccountView::fromAccount($world->trackedAccount(), null);

        $dump = print_r($view, true);

        static::assertStringNotContainsString(AccountWorld::SIRET, $dump);
        static::assertStringContainsString('***0074', $dump);
        static::assertStringNotContainsString('2025-03-01', $dump);
        static::assertStringContainsString('account-1', $dump);
        static::assertSame(
            [
                'accountId' => 'account-1',
                'tenantId' => 'tenant-1',
                'siret' => '***0074',
                'hasBirthDate' => true,
                'mandatePresent' => false,
                'periodicity' => 'M',
                'acreExemption' => false,
                'mandate' => null,
                'lastSyncedAt' => '2026-11-01T08:00:00Z',
                'createdAt' => '2026-11-01T08:00:00Z',
                'updatedAt' => '2026-11-01T08:00:00Z',
            ],
            $view->__debugInfo(),
        );
    }

    public function testAMandateViewOfAnActiveMandate(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();

        $view = MandateView::fromMandate($world->activeMandateOf($account));

        static::assertSame('mandate-1', $view->mandateId);
        static::assertSame('tenant-1', $view->tenantId);
        static::assertSame('account-1', $view->accountId);
        static::assertSame('imported', $view->origin);
        static::assertSame('notified', $view->state);
        static::assertNull($view->cancellationOrigin);
        static::assertSame('2026-11-02T08:00:00Z', $view->notifiedAt);
        static::assertNull($view->cancelledAt);
        static::assertSame('2026-11-02T08:00:00Z', $view->updatedAt);
    }

    public function testAMandateViewOfACancelledMandateWithoutAccount(): void
    {
        $world = new AccountWorld();

        $view = MandateView::fromMandate($world->cancelledMandate());

        static::assertNull($view->accountId);
        static::assertSame('cancelled', $view->state);
        static::assertSame('cancelled', $view->cancellationOrigin);
        static::assertSame('2026-11-10T08:00:00Z', $view->cancelledAt);
        static::assertSame('2026-11-10T08:00:00Z', $view->updatedAt);
        static::assertObjectNotHasProperty('siret', $view);
    }

    public function testASelectorHoldsItsReferenceAndMasksItWhenDumped(): void
    {
        $byId = AccountSelector::byAccountId(AccountId::fromString('account-1'));
        $bySiret = AccountSelector::bySiret(AccountWorld::siret());

        static::assertInstanceOf(AccountId::class, $byId->reference);
        static::assertSame('account-1', $byId->reference->value());
        static::assertInstanceOf(Siret::class, $bySiret->reference);
        static::assertSame(AccountWorld::SIRET, $bySiret->reference->toString());
        static::assertSame(['reference' => '***'], $byId->__debugInfo());
        static::assertSame(['reference' => '***'], $bySiret->__debugInfo());
        static::assertStringNotContainsString(AccountWorld::SIRET, print_r($bySiret, true));
    }

    public function testAnErasureHoldsIdentifiersOnly(): void
    {
        $erasure = new AccountErasure('account-1', ['mandate-1'], ['declaration-1', 'declaration-2'], ['payment-1']);
        $untracked = new AccountErasure(null, [], [], []);

        static::assertSame('account-1', $erasure->accountId);
        static::assertSame(['mandate-1'], $erasure->mandateIds);
        static::assertSame(['declaration-1', 'declaration-2'], $erasure->declarationRecordIds);
        static::assertSame(['payment-1'], $erasure->paymentRecordIds);
        static::assertNull($untracked->accountId);
    }

    public function testASubmissionErrorViewMasksTheUrssafTextWhenDumped(): void
    {
        $views = SubmissionErrorView::fromErrors([
            SubmissionError::of('ER_API_050_0070', 'unknown_mandate', 'Mandat inconnu', 'Detail'),
            SubmissionError::of('urssaf.rejected', 'unknown', null, null),
        ]);

        static::assertCount(2, $views);
        static::assertSame('ER_API_050_0070', $views[0]->code);
        static::assertSame('unknown_mandate', $views[0]->kind);
        static::assertSame('Mandat inconnu', $views[0]->message);
        static::assertSame('Detail', $views[0]->description);
        static::assertSame(['code' => 'ER_API_050_0070', 'kind' => 'unknown_mandate', 'message' => '***', 'description' => '***'], $views[0]->__debugInfo());
        static::assertSame(['code' => 'urssaf.rejected', 'kind' => 'unknown', 'message' => null, 'description' => null], $views[1]->__debugInfo());
        static::assertStringNotContainsString('Mandat inconnu', print_r($views[0], true));
        static::assertSame([], SubmissionErrorView::fromErrors([]));
    }
}
