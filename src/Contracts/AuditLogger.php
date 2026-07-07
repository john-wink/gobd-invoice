<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Appends entries to the tamper-evident, insert-only audit log. Every lifecycle
 * transition and content change MUST be recorded (GoBD Nachvollziehbarkeit).
 * See docs/research/01-gobd-compliance.md.
 */
interface AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function append(InvoiceDocument $invoiceDocument, string $event, array $context = []): Model;

    /**
     * Re-walk the document's audit chain and confirm it has not been tampered
     * with: every entry's recorded hash matches a recomputation and links to the
     * previous entry's hash. Returns `false` on any break.
     */
    public function verify(InvoiceDocument $invoiceDocument): bool;
}
