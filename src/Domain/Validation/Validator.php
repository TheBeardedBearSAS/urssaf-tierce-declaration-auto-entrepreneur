<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation;

/**
 * Validates a subject against the URSSAF rules and reports every finding instead of failing fast.
 *
 * @template T of object
 */
interface Validator
{
    /**
     * @param T $subject
     */
    public function validate(object $subject): ValidationResult;
}
