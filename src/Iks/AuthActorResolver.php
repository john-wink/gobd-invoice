<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Iks;

use Illuminate\Support\Facades\Auth;
use JohnWink\GobdInvoice\Contracts\ActorResolver;

/**
 * The default {@see ActorResolver}: the authenticated user's id (as a string),
 * or null when no user is authenticated.
 */
final readonly class AuthActorResolver implements ActorResolver
{
    public function resolve(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
