<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use SensitiveParameter;

/**
 * Query string of the operations that carry parameters (ADR 0002): RFC 3986 percent-encoding (a space is `%20`,
 * never `+`).
 *
 * The parameters carry personal data (employer number, birth dates, NIR): a query string is never logged and never put
 * in an exception message (ADR 0010).
 */
final class QueryEncoding
{
    /**
     * Parameters whose value is null are dropped (PHP skips them). Empty string when there is no parameter left; the
     * result has no leading `?`.
     *
     * @param array<string, string|null> $parameters
     */
    public static function build(#[SensitiveParameter] array $parameters): string
    {
        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
