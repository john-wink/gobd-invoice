<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Audit\ContentHasher;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;
use JohnWink\GobdInvoice\Contracts\TotalsCalculator;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Events\DocumentCancelled;
use JohnWink\GobdInvoice\Events\DocumentDrafted;
use JohnWink\GobdInvoice\Events\DocumentFinalized;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Exceptions\InvalidStatusTransitionException;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxBreakdown;
use JohnWink\GobdInvoice\ValueObjects\TaxBreakdownLine;

/**
 * The package's primary entry point (resolved by the {@see Facades\GobdInvoice}
 * facade). Covers the core lifecycle implemented in milestones M1–M3:
 * draft → finalize (Festschreibung) → verify, plus cancellation via a linked
 * Storno. E-invoice (M5) and PDF rendering (M4) are added by their drivers; see
 * docs/ROADMAP.md.
 */
final readonly class GobdInvoiceManager
{
    public function __construct(
        private NumberSequenceGenerator $numberSequenceGenerator,
        private TotalsCalculator $totalsCalculator,
        private AuditLogger $auditLogger,
        private ContentHasher $contentHasher,
    ) {}

    /**
     * Create an editable draft. Lines are arrays of:
     * `description`, `quantity` (decimal string), `unit?`, and either
     * `unit_price` (decimal string) or `unit_price_minor` (int), `discount_minor?`,
     * `tax_rate?` (e.g. "19.0"), `tax_category?` (UNCL5305 code).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function draft(DocumentType $documentType, array $attributes = [], array $lines = []): Document
    {
        /** @var class-string<Document> $model */
        $model = config('gobd-invoice.models.document', Document::class);

        $currency = $this->toStringValue($attributes['currency'] ?? null, Config::string('gobd-invoice.currency', 'EUR'));

        $document = new $model;
        $document->type = $documentType;
        $document->status = DocumentStatus::Draft;
        $document->currency = $currency;
        $document->series = $this->toStringValue($attributes['series'] ?? null, $documentType->defaultSeries());
        $document->issue_date = $this->parseDate($attributes['issue_date'] ?? null);
        $document->service_date = $this->parseDate($attributes['service_date'] ?? null);
        $document->is_financial_sector = isset($attributes['is_financial_sector'])
            ? (bool) $attributes['is_financial_sector']
            : Config::boolean('gobd-invoice.retention.financial_sector', false);
        $document->retention_class = 'voucher';

        if (isset($attributes['meta']) && is_array($attributes['meta'])) {
            /** @var array<string, mixed> $meta */
            $meta = $attributes['meta'];
            $document->meta = $meta;
        }

        if (($attributes['documentable'] ?? null) instanceof Model) {
            $document->documentable()->associate($attributes['documentable']);
        }

        $document->save();

        foreach (array_values($lines) as $index => $line) {
            $document->lines()->create($this->buildLineAttributes($index + 1, $line, $currency));
        }

        $document->load('lines');

        event(new DocumentDrafted($document));

        return $document;
    }

    /**
     * Finalize (festschreiben) a draft: compute totals, assign the number,
     * snapshot + hash the content, set the retention window and write the audit
     * entry. After this the tax-relevant content is immutable.
     */
    public function finalize(Document $document): Document
    {
        if ($document->documentStatus() !== DocumentStatus::Draft) {
            throw InvalidStatusTransitionException::between($document->status, DocumentStatus::Finalized);
        }

        $document->loadMissing('lines');

        $issuedAt = $document->issue_date ?? Date::now();
        $series = (string) ($document->series ?? $document->type->defaultSeries());

        // A gap-tolerant generator allocates the number up front in its own short
        // lock (high throughput); the gapless default defers allocation into the
        // transaction below so a rollback un-burns it.
        $number = $this->numberSequenceGenerator->allocatesWithinTransaction()
            ? null
            : $this->numberSequenceGenerator->next($document->type, $series, $issuedAt->year);

        // Atomic Festschreibung: number allocation (when gapless), content hash,
        // the document save and the audit entry commit together or not at all, so
        // a failed finalize never strands a number-bearing draft or an audit-less
        // finalized document. Keep this transaction tight: slow work (PDF /
        // e-invoice rendering, M4/M5) must run AFTER finalize, never inside it.
        DB::transaction(function () use ($document, $issuedAt, $series, $number): void {
            $taxBreakdown = $this->totalsCalculator->calculate($document->lines->all(), $document->currency);

            $number ??= $this->numberSequenceGenerator->next($document->type, $series, $issuedAt->year);

            $document->number = (string) $number;
            $document->series = $number->series;
            $document->year = $number->year;
            $document->sequence = $number->sequence;
            $document->net_total = $taxBreakdown->netTotal->minorUnits;
            $document->vat_total = $taxBreakdown->vatTotal->minorUnits;
            $document->gross_total = $taxBreakdown->grossTotal->minorUnits;
            $document->tax_breakdown = $this->breakdownGroups($taxBreakdown);
            $document->issue_date = $issuedAt;

            [$document->retention_class, $document->retention_until] = $this->retentionFor($document, $issuedAt);

            $payload = $this->buildSnapshot($document);
            $document->finalized_payload = $payload;
            $document->content_hash = $this->contentHasher->hash($payload);
            $document->finalized_at = Date::now();
            $document->status = DocumentStatus::Finalized;
            $document->save();

            $this->auditLogger->append($document, 'finalized', [
                'number' => $document->number,
                'content_hash' => $document->content_hash,
            ]);
        });

        event(new DocumentFinalized($document));

        return $document;
    }

    /**
     * Confirm the document has not been tampered with since finalization, in
     * three layers: (1) the stored snapshot still matches its recorded hash;
     * (2) the LIVE document + lines still match that snapshot (catches a row
     * edited directly in the database, past the model immutability guards);
     * (3) the append-only audit chain is intact. Any failure returns `false`.
     */
    public function verify(Document $document): bool
    {
        $contentHash = $document->content_hash;

        if ($document->finalized_payload === null || $contentHash === null) {
            return false;
        }

        // (1) The frozen snapshot is internally consistent with its hash.
        if (! hash_equals($contentHash, $this->contentHasher->hash($document->finalized_payload))) {
            return false;
        }

        // (2) The live document and its lines still reconcile with the snapshot.
        $document->loadMissing('lines');

        if (! hash_equals($contentHash, $this->contentHasher->hash($this->buildSnapshot($document)))) {
            return false;
        }

        // (3) The audit hash chain is unbroken.
        return $this->auditLogger->verify($document);
    }

    /**
     * Cancel a finalized, tax-relevant document by issuing a linked Storno with
     * negated amounts (Storno statt Löschen). The original is never deleted; it
     * moves to the Cancelled status. Returns the new Storno document.
     */
    public function cancel(Document $document, string $reason): Document
    {
        throw_unless($document->isImmutable(), GobdInvoiceException::class, 'Only a finalized, tax-relevant document can be cancelled via Storno.');

        if ($document->documentStatus() === DocumentStatus::Cancelled) {
            throw new GobdInvoiceException("Document [{$document->number}] is already cancelled.");
        }

        $document->loadMissing('lines');

        $stornoLines = $document->lines->map(static fn (DocumentLine $documentLine): array => [
            'description' => $documentLine->description,
            'quantity' => '1',
            'unit' => $documentLine->unit,
            'unit_price_minor' => -$documentLine->line_net_minor,
            'tax_rate' => $documentLine->tax_rate,
            'tax_category' => $documentLine->tax_category,
        ])->values()->all();

        // Atomic Storno: the new Storno's draft+finalization, the original's flip
        // to Cancelled, and the 'cancelled' audit entry commit together. A failure
        // anywhere rolls the whole correction back, so there can be no orphan
        // Storno without its cancelled original (Storno statt Löschen).
        $storno = DB::transaction(function () use ($document, $reason, $stornoLines): Document {
            $storno = $this->draft(DocumentType::Storno, [
                'currency' => $document->currency,
                'series' => DocumentType::Storno->defaultSeries(),
                'service_date' => $document->service_date,
                'is_financial_sector' => $document->is_financial_sector,
                'meta' => ['reason' => $reason, 'storno_of' => $document->number],
            ], $stornoLines);

            $storno->source_document_id = $document->id;
            $storno->save();
            $this->finalize($storno);

            $document->status = DocumentStatus::Cancelled;
            $document->save();

            $this->auditLogger->append($document, 'cancelled', [
                'storno' => $storno->number,
                'reason' => $reason,
            ]);

            return $storno;
        });

        event(new DocumentCancelled($document, $storno));

        return $storno;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function buildLineAttributes(int $position, array $line, string $currency): array
    {
        $unitPrice = isset($line['unit_price_minor'])
            ? Money::fromMinorUnits($this->toIntValue($line['unit_price_minor']), $currency)
            : Money::fromDecimal($this->toStringValue($line['unit_price'] ?? null, '0'), $currency);

        $quantity = $this->toStringValue($line['quantity'] ?? null, '1');

        $discount = isset($line['discount_minor'])
            ? Money::fromMinorUnits($this->toIntValue($line['discount_minor']), $currency)
            : Money::zero($currency);

        $money = $unitPrice->multipliedBy($quantity)->minus($discount);

        return [
            'position' => $position,
            'description' => $this->toStringValue($line['description'] ?? null),
            'quantity' => $quantity,
            'unit' => isset($line['unit']) ? $this->toStringValue($line['unit']) : null,
            'unit_price_minor' => $unitPrice->minorUnits,
            'discount_minor' => $discount->minorUnits,
            'line_net_minor' => $money->minorUnits,
            'tax_rate' => $this->toStringValue($line['tax_rate'] ?? null, Config::string('gobd-invoice.tax.standard_rate', '19.0')),
            'tax_category' => $this->toStringValue($line['tax_category'] ?? null, TaxCategory::Standard->value),
            'currency' => $currency,
        ];
    }

    /**
     * @return array{0: string, 1: Carbon}
     */
    private function retentionFor(Document $document, Carbon $issuedAt): array
    {
        $financial = $document->is_financial_sector
            || Config::boolean('gobd-invoice.retention.financial_sector', false);

        $years = $financial ? 10 : Config::integer('gobd-invoice.retention.voucher_years', 8);
        $class = $financial ? 'financial_sector' : 'voucher';

        // The clock starts at the END of the calendar year (§147 Abs. 4 AO).
        $until = $issuedAt->copy()->endOfYear()->addYears($years)->startOfDay();

        return [$class, $until];
    }

    /**
     * The canonical content snapshot, built purely from the document's persisted
     * columns and its live lines. finalize() stores this as `finalized_payload`;
     * verify() rebuilds it from live data — so both MUST produce an identical
     * structure for the content hash to reconcile.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshot(Document $document): array
    {
        return [
            'type' => $document->type->value,
            'number' => $document->number,
            'series' => $document->series,
            'year' => $document->year,
            'sequence' => $document->sequence,
            'currency' => $document->currency,
            'issue_date' => $document->issue_date?->toDateString(),
            'service_date' => $document->service_date?->toDateString(),
            'net_total' => $document->net_total,
            'vat_total' => $document->vat_total,
            'gross_total' => $document->gross_total,
            'tax_breakdown' => $document->tax_breakdown,
            'lines' => $document->lines->map(static fn (DocumentLine $documentLine): array => [
                'position' => $documentLine->position,
                'description' => $documentLine->description,
                'quantity' => $documentLine->quantity,
                'unit' => $documentLine->unit,
                'unit_price_minor' => $documentLine->unit_price_minor,
                'discount_minor' => $documentLine->discount_minor,
                'line_net_minor' => $documentLine->line_net_minor,
                'tax_rate' => $documentLine->tax_rate,
                'tax_category' => $documentLine->tax_category,
            ])->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breakdownGroups(TaxBreakdown $taxBreakdown): array
    {
        return array_map(static fn (TaxBreakdownLine $taxBreakdownLine): array => [
            'category' => $taxBreakdownLine->rate->categoryCode(),
            'rate' => $taxBreakdownLine->rate->percent(),
            'net' => $taxBreakdownLine->net->minorUnits,
            'vat' => $taxBreakdownLine->vat->minorUnits,
            'gross' => $taxBreakdownLine->gross->minorUnits,
        ], $taxBreakdown->lines);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Date::instance($value);
        }

        if (is_string($value)) {
            return Date::parse($value);
        }

        throw new GobdInvoiceException('Unsupported date value supplied to gobd-invoice.');
    }

    private function toStringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private function toIntValue(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
