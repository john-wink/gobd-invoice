<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Numbering;

use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Enums\DocumentType;

/**
 * Shared, overridable resolution of the two host-customizable inputs to number
 * generation: the counter row KEY and the printed FORMAT. Both generators
 * ({@see LockingSequenceGenerator}, {@see FastSequenceGenerator}) resolve them
 * through these methods so a host application can subclass the behaviour — e.g.
 * scope the counter per tenant, or build a per-type format from tenant settings —
 * without reimplementing the race-safe increment. See
 * docs/research/08-package-architecture.md (B8).
 */
trait ResolvesSequenceKeyAndFormat
{
    /**
     * The columns that uniquely identify a counter row. Overriding this lets a
     * host scope the counter (e.g. append a tenant id to the series) while the
     * returned {@see DocumentNumber} still carries the caller's original series.
     *
     * @return array{document_type: string, series: string, year: int}
     */
    protected function sequenceKeys(DocumentType $documentType, string $series, int $year): array
    {
        return [
            'document_type' => $documentType->value,
            'series' => $series,
            'year' => $year,
        ];
    }

    /**
     * The format template applied to the allocated sequence value. Overriding
     * this lets a host build a per-(type, series, year) template — the default
     * is the single global `gobd-invoice.numbering.format`.
     */
    protected function formatFor(DocumentType $documentType, string $series, int $year): string
    {
        return Config::string('gobd-invoice.numbering.format', '{type}-{year}-{seq:5}');
    }
}
