<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\ValueObjects\DocumentNumber;

/**
 * Produces the next document number for a (type, series, year) sequence in a
 * race-safe way. §14 Abs. 4 Nr. 4 UStG requires a unique number; the default
 * implementation also makes the sequence gap-free by assigning only at
 * finalization. See docs/research/08-package-architecture.md (B8).
 */
interface NumberSequenceGenerator
{
    public function next(DocumentType $documentType, string $series, int $year): DocumentNumber;

    /**
     * Whether finalize() should allocate the number INSIDE its own transaction.
     *
     * `true` (gapless): the increment is part of the Festschreibung transaction,
     * so a failed finalize rolls it back and the sequence stays gapless — at the
     * cost of holding the counter row lock for the whole finalize, which
     * serializes finalizations of the same (type, series, year).
     *
     * `false` (gap-tolerant, high throughput): the number is allocated up front in
     * a short, independently-committed lock, so the row lock is released before the
     * heavier finalize work. A finalize that fails afterwards leaves an explicable
     * gap (UStG requires uniqueness, not gaplessness). Throughput then scales with
     * the number of distinct sequences (e.g. one series per tenant).
     */
    public function allocatesWithinTransaction(): bool;
}
