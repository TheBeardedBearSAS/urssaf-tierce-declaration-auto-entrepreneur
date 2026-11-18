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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Output;

/**
 * Reads the two-column tables ("Field | Value") printed by the console commands, and finds the payload files.
 */
final class Fields
{
    private function __construct() {}

    /**
     * @return array<string, string>
     */
    public static function of(string $display): array
    {
        $fields = [];
        foreach (Output::rows($display) as $row) {
            if (\count($row) !== 2 || $row[0] === 'Field') {
                continue;
            }

            $fields[$row[0]] = $row[1];
        }

        return $fields;
    }

    /**
     * The configuration of a second tenant (the secret is an environment placeholder, like the one of the first).
     *
     * @return array{credentials: array{tenant-2: array{client_id: string, client_secret: string}}}
     */
    public static function secondTenant(): array
    {
        // @mago-expect lint:no-literal-password The secret is an environment placeholder, set by the test kernel.
        return ['credentials' => ['tenant-2' => ['client_id' => 'client-2', 'client_secret' => '%env(URSSAF_TEST_SECRET)%']]];
    }

    /**
     * The path of a payload file of tests/Functional/Symfony/Command/payloads.
     */
    public static function payload(string $name): string
    {
        return __DIR__ . '/payloads/' . $name;
    }
}
