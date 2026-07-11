<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

/**
 * Resolves the identity of the actor performing a lifecycle action, for the
 * audit trail (who did what — GoBD Nachvollziehbarkeit) and the segregation-of-
 * duties gate. A host binds its own implementation to identify actors however it
 * authenticates (a user id, an API-client id, a system-process marker, ...).
 */
interface ActorResolver
{
    /**
     * A stable identifier for the current actor, or null when none can be
     * determined (e.g. an unauthenticated CLI/queue context).
     */
    public function resolve(): ?string;
}
