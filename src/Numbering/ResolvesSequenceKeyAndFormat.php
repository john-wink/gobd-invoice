<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Numbering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Database\Tenancy;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Models\NumberSequence;

/**
 * Shared, overridable resolution of the two host-customizable inputs to number
 * generation: the counter row KEY and the printed FORMAT. Both generators
 * ({@see LockingSequenceGenerator}, {@see FastSequenceGenerator}) resolve them
 * through these methods so a host application can subclass the behaviour — e.g.
 * build a per-type format from tenant settings — without reimplementing the
 * race-safe increment. See docs/research/08-package-architecture.md (B8).
 */
trait ResolvesSequenceKeyAndFormat
{
    /**
     * The columns that uniquely identify a counter row. In multi-tenant mode the
     * tenant is part of the key, so every tenant runs its own counters; a
     * missing tenant fails loud instead of falling back to a shared counter.
     *
     * @return array<string, int|string>
     */
    protected function sequenceKeys(DocumentType $documentType, string $series, int $year, int|string|null $tenant): array
    {
        return [
            ...Tenancy::attributesFor($tenant),
            'document_type' => $documentType->value,
            'series' => $series,
            'year' => $year,
        ];
    }

    /**
     * Create the counter row idempotently. The key may carry the tenant column,
     * which a host keeps guarded against mass assignment; the package writes it
     * itself here, from the tenant of the document being numbered.
     *
     * @param  class-string<NumberSequence>  $model
     * @param  array<string, int|string>  $keys
     */
    protected function ensureCounterExists(string $model, array $keys): void
    {
        Model::unguarded(static fn (): NumberSequence => $model::query()->firstOrCreate($keys, ['current_value' => 0]));
    }

    /**
     * The format template applied to the allocated sequence value. Overriding
     * this lets a host build a per-(tenant, type, series, year) template — the
     * default is the single global `gobd-invoice.numbering.format`.
     */
    protected function formatFor(DocumentType $documentType, string $series, int $year, int|string|null $tenant): string
    {
        return Config::string('gobd-invoice.numbering.format', '{type}-{year}-{seq:5}');
    }
}
