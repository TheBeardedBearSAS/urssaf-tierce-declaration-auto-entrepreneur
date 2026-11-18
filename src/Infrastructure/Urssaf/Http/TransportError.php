<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Sanitised cause of a transport failure (ADR 0010, ADR 0002): only the class of the PSR-18 exception is kept.
 *
 * The PSR-18 message usually embeds the full URI, query included (SIRET, RUM), so neither
 * that message nor the original exception ever travels in the exception chain.
 *
 * @internal
 */
final class TransportError extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function fromClientException(ClientExceptionInterface $exception): self
    {
        return new self(\sprintf('The PSR-18 client failed (%s).', $exception::class));
    }
}
