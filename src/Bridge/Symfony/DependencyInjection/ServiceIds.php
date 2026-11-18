<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

/**
 * Identifiers of the services of the bundle that are not named after a class or an interface, and the names of the tags
 * (ADR 0015: everything is prefixed "urssaf_tierce_declaration_auto_entrepreneur", nothing is shared with the other URSSAF packages).
 *
 * The other services have the id of their class or port ({@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock}...).
 */
final class ServiceIds
{
    public const SYMFONY_HTTP_CLIENT = 'urssaf_tierce_declaration_auto_entrepreneur.http_client';

    /** Also the PSR-17 request and stream factories of the HTTP gateway. */
    public const PSR18_CLIENT = 'urssaf_tierce_declaration_auto_entrepreneur.psr18_client';
    public const API_CONFIGURATION = 'urssaf_tierce_declaration_auto_entrepreneur.api_configuration';
    public const TOKEN_PROVIDER = 'urssaf_tierce_declaration_auto_entrepreneur.token_provider';

    /** Token provider of urssaf:tdae:check: its own in-memory store, so that every check asks URSSAF. */
    public const PROBE_TOKEN_PROVIDER = 'urssaf_tierce_declaration_auto_entrepreneur.probe_token_provider';
    public const JSON_CLIENT = 'urssaf_tierce_declaration_auto_entrepreneur.json_client';

    /** Tag of the installation checks of "urssaf:tdae:check" (priority: report order). */
    public const TAG_INSTALLATION_CHECK = 'urssaf_tierce_declaration_auto_entrepreneur.installation_check';

    /** Container parameter: the tenants processed by the commands that cannot discover them (list of strings). */
    public const PARAMETER_TENANTS = 'urssaf_tierce_declaration_auto_entrepreneur.tenants';

    /** Monolog channel of the logs of the package (never personal data, ADR 0010). */
    public const LOGGER_CHANNEL = 'urssaf_tdae';
}
