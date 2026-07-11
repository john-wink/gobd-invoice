<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Models\Document;

/**
 * An internal-control (IKS) gate consulted BEFORE the sensitive lifecycle
 * transitions. Implementations veto an action by throwing
 * {@see \JohnWink\GobdInvoice\Exceptions\IksViolationException} — this is a
 * PREVENTIVE control (segregation of duties / Vier-Augen-Prinzip), distinct from
 * the after-the-fact audit trail. The default policy is permissive; a host opts
 * into a stricter one (e.g. four-eyes) via config or by binding its own.
 */
interface SegregationPolicy
{
    /**
     * @param  string|null  $actor  the actor attempting to finalize (from {@see ActorResolver})
     */
    public function assertCanFinalize(Document $document, ?string $actor): void;

    /**
     * @param  string|null  $actor  the actor attempting to cancel (from {@see ActorResolver})
     */
    public function assertCanCancel(Document $document, ?string $actor): void;
}
