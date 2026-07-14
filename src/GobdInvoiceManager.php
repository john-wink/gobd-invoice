<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JohnWink\En16931\ValidationResult;
use JohnWink\En16931\Violation;
use JohnWink\GobdInvoice\Audit\ContentHasher;
use JohnWink\GobdInvoice\Contracts\ActorResolver;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\DatevExporter;
use JohnWink\GobdInvoice\Contracts\DocumentContentValidator;
use JohnWink\GobdInvoice\Contracts\DocumentTotalsCalculator;
use JohnWink\GobdInvoice\Contracts\DunningInterestCalculator;
use JohnWink\GobdInvoice\Contracts\EInvoicePdfBuilder;
use JohnWink\GobdInvoice\Contracts\EInvoiceReader;
use JohnWink\GobdInvoice\Contracts\EInvoiceSerializer;
use JohnWink\GobdInvoice\Contracts\EInvoiceValidator;
use JohnWink\GobdInvoice\Contracts\GobdDataExporter;
use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;
use JohnWink\GobdInvoice\Contracts\SegregationPolicy;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Enums\PriceMode;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Events\DocumentCancelled;
use JohnWink\GobdInvoice\Events\DocumentDrafted;
use JohnWink\GobdInvoice\Events\DocumentFinalized;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Exceptions\DunningException;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Exceptions\InvalidStatusTransitionException;
use JohnWink\GobdInvoice\Export\Datev\DatevExportOptions;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\ValueObjects\AdvanceDeduction;
use JohnWink\GobdInvoice\ValueObjects\AllowanceCharge;
use JohnWink\GobdInvoice\ValueObjects\DocumentTotals;
use JohnWink\GobdInvoice\ValueObjects\DunningAssessment;
use JohnWink\GobdInvoice\ValueObjects\DunningOptions;
use JohnWink\GobdInvoice\ValueObjects\ExchangeRate;
use JohnWink\GobdInvoice\ValueObjects\LineInput;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\ParsedEInvoice;
use JohnWink\GobdInvoice\ValueObjects\Party;
use JohnWink\GobdInvoice\ValueObjects\PaymentTerms;
use JohnWink\GobdInvoice\ValueObjects\TaxBreakdown;
use JohnWink\GobdInvoice\ValueObjects\TaxBreakdownLine;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;
use JohnWink\GobdInvoice\ValueObjects\TotalsInput;

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
        private DocumentTotalsCalculator $documentTotalsCalculator,
        private DocumentContentValidator $documentContentValidator,
        private AuditLogger $auditLogger,
        private ContentHasher $contentHasher,
        private EInvoiceSerializer $eInvoiceSerializer,
        private EInvoiceReader $eInvoiceReader,
        private EInvoiceValidator $eInvoiceValidator,
        private EInvoicePdfBuilder $eInvoicePdfBuilder,
        private GobdDataExporter $gobdDataExporter,
        private DatevExporter $datevExporter,
        private DunningInterestCalculator $dunningInterestCalculator,
        private ActorResolver $actorResolver,
        private SegregationPolicy $segregationPolicy,
    ) {}

    /**
     * Create an editable draft. Lines are arrays of:
     * `description`, `quantity` (decimal string), `unit?`, and either
     * `unit_price` (decimal string) or `unit_price_minor` (int), `price_mode?`
     * (`net` | `gross`), `discount_minor?`, `adjustments?` (line allowances/
     * charges), `tax_rate?` (e.g. "19.0"), `tax_category?` (UNCL5305 code).
     *
     * Document-level `$attributes` may carry `adjustments` (allowances/charges),
     * `payment_terms` (Skonto), `accounting_rate` (§16 Abs. 6 UStG rate to EUR)
     * and `paid_minor`.
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
        $document->service_period_start = $this->parseDate($attributes['service_period_start'] ?? null);
        $document->service_period_end = $this->parseDate($attributes['service_period_end'] ?? null);
        $document->is_financial_sector = isset($attributes['is_financial_sector'])
            ? (bool) $attributes['is_financial_sector']
            : Config::boolean('gobd-invoice.retention.financial_sector', false);
        $document->retention_class = 'voucher';

        if (isset($attributes['paid_minor'])) {
            $document->paid_total = $this->toIntOrFail($attributes['paid_minor'], 'paid_minor');
        }

        // Document-level allowances/charges, payment terms and the accounting-
        // currency rate are validated into value objects now (fail loud) and
        // stored in canonical form so finalize() can rebuild them faithfully.
        $document->document_adjustments = $this->normalizeAdjustments($attributes['adjustments'] ?? null, $currency);
        $document->payment_terms = $this->normalizePaymentTerms($attributes['payment_terms'] ?? null);
        $document->accounting_rate = $this->normalizeAccountingRate($attributes['accounting_rate'] ?? null);
        $document->seller = $this->normalizeParty($attributes['seller'] ?? null);
        $document->buyer = $this->normalizeParty($attributes['buyer'] ?? null);

        // Establish the host link and source link BEFORE resolving advance
        // deductions (so the cross-order guard sees the order) and before the
        // DocumentDrafted event (so listeners see a fully-linked draft).
        if (($attributes['documentable'] ?? null) instanceof Model) {
            $document->documentable()->associate($attributes['documentable']);
        } elseif (isset($attributes['documentable_type'], $attributes['documentable_id'])) {
            // Raw morph columns, e.g. carried forward by convert() without a Model.
            $document->documentable_type = $this->toStringValue($attributes['documentable_type']);
            $document->documentable_id = $this->toIntOrFail($attributes['documentable_id'], 'documentable_id');
        }

        if (isset($attributes['source_document_id'])) {
            $document->source_document_id = $this->toIntOrFail($attributes['source_document_id'], 'source_document_id');
        }

        $document->advance_deductions = $this->resolveAdvanceDeductions(
            $attributes['deducts'] ?? null,
            $currency,
            $document->documentable_type,
            $document->documentable_id,
        );

        if (isset($attributes['meta']) && is_array($attributes['meta'])) {
            /** @var array<string, mixed> $meta */
            $meta = $attributes['meta'];
            $document->meta = $meta;
        }

        // IKS accountability: record who created the draft (for the four-eyes gate).
        $document->created_by = $this->actorResolver->resolve();

        $document->save();

        foreach (array_values($lines) as $index => $line) {
            $document->lines()->create($this->buildLineAttributes($index + 1, $line, $currency));
        }

        $document->load('lines');

        event(new DocumentDrafted($document));

        return $document;
    }

    /**
     * Update an editable draft in place: re-apply the given draft attributes and
     * REPLACE its line items. Only a draft may be edited — a finalized document
     * is immutable (Unveränderbarkeit), so this throws for anything else. Line
     * and document `$attributes`/`$lines` follow the same shape as {@see draft()}.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(Document $document, array $attributes = [], array $lines = []): Document
    {
        throw_unless($document->documentStatus() === DocumentStatus::Draft, GobdInvoiceException::class, "Only a draft can be edited; [{$document->number}] is not a draft.");

        // Atomic: the line items are deleted and recreated, so a failure mid-way
        // (e.g. an invalid amount) must not leave the draft with its lines gone.
        DB::transaction(function () use ($document, $attributes, $lines): void {
            $currency = $this->toStringValue($attributes['currency'] ?? null, (string) $document->currency);
            $document->currency = $currency;

            if (array_key_exists('issue_date', $attributes)) {
                $document->issue_date = $this->parseDate($attributes['issue_date']);
            }
            if (array_key_exists('service_date', $attributes)) {
                $document->service_date = $this->parseDate($attributes['service_date']);
            }
            if (array_key_exists('service_period_start', $attributes)) {
                $document->service_period_start = $this->parseDate($attributes['service_period_start']);
            }
            if (array_key_exists('service_period_end', $attributes)) {
                $document->service_period_end = $this->parseDate($attributes['service_period_end']);
            }
            if (array_key_exists('seller', $attributes)) {
                $document->seller = $this->normalizeParty($attributes['seller']);
            }
            if (array_key_exists('buyer', $attributes)) {
                $document->buyer = $this->normalizeParty($attributes['buyer']);
            }
            if (isset($attributes['meta']) && is_array($attributes['meta'])) {
                /** @var array<string, mixed> $meta */
                $meta = $attributes['meta'];
                $document->meta = $meta;
            }
            if (isset($attributes['documentable_type'], $attributes['documentable_id'])) {
                $document->documentable_type = $this->toStringValue($attributes['documentable_type']);
                $document->documentable_id = $this->toIntOrFail($attributes['documentable_id'], 'documentable_id');
            }

            $document->document_adjustments = $this->normalizeAdjustments($attributes['adjustments'] ?? null, $currency);
            $document->save();

            // Replace the line items (a draft carries no legal identity yet).
            $document->lines()->delete();
            foreach (array_values($lines) as $index => $line) {
                $document->lines()->create($this->buildLineAttributes($index + 1, $line, $currency));
            }
        });

        $document->load('lines');

        event(new DocumentDrafted($document));

        return $document;
    }

    /**
     * Finalize (festschreiben) a draft: compute the full EN 16931 totals chain,
     * assign the number, snapshot + hash the content, set the retention window
     * and write the audit entry. After this the tax-relevant content is immutable.
     */
    public function finalize(Document $document): Document
    {
        // IKS segregation of duties (preventive control): may this actor finalize?
        // Gates the primary Festschreibung only; the Storno that cancel()
        // festschreibt internally is already gated by assertCanCancel(), so it
        // uses the ungated performFinalize() (else the canceller, who both drafts
        // and finalizes the Storno, would trip the four-eyes rule on themselves).
        $this->segregationPolicy->assertCanFinalize($document, $this->actorResolver->resolve());

        return $this->performFinalize($document);
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
     * Serialize a finalized document into a structured EN 16931 e-invoice
     * (ZUGFeRD/Factur-X or XRechnung CII XML per the configured format/profile).
     * The document must be finalized and of an invoice type; the bound
     * {@see EInvoiceSerializer} enforces this and refuses non-compliant profiles.
     */
    public function eInvoiceXml(Document $document): string
    {
        $document->loadMissing('lines');

        $xml = $this->eInvoiceSerializer->serialize($document);

        if (Config::boolean('gobd-invoice.einvoice.validate_on_export', false)) {
            $result = $this->eInvoiceValidator->validate($xml);
            throw_unless($result->isValid(), GobdInvoiceException::class, 'The exported e-invoice is not EN 16931 conformant: '.$this->summarizeViolations($result));
        }

        return $xml;
    }

    /**
     * Embed the finalized document's CII XML into the supplied base PDF, yielding
     * a hybrid ZUGFeRD/Factur-X PDF/A-3. The visual PDF is rendered by the host.
     */
    public function eInvoicePdf(Document $document, string $basePdf): string
    {
        return $this->eInvoicePdfBuilder->build($document, $basePdf);
    }

    /**
     * Export the given (finalized) documents as a GDPdU data set for tax-audit
     * data access (Z3) — the CSV tables plus the index.xml descriptor, keyed by
     * filename. The host supplies the documents (e.g. a date-range query).
     *
     * @param  iterable<Document>  $documents
     * @return array<string, string>
     */
    public function exportGdpdu(iterable $documents): array
    {
        return $this->gobdDataExporter->export($documents);
    }

    /**
     * Export the given (finalized) documents as a DATEV EXTF Buchungsstapel — the
     * booking batch a German tax advisor imports into DATEV. Returns the
     * Windows-1252-encoded file content. The host supplies the documents (e.g. a
     * date-range query) and the export metadata (Berater/Mandant/fiscal year).
     *
     * @param  iterable<Document>  $documents
     */
    public function exportDatev(iterable $documents, DatevExportOptions $datevExportOptions): string
    {
        return $this->datevExporter->export($documents, $datevExportOptions);
    }

    /**
     * Assess the §288 BGB default interest, flat fee and total for an overdue
     * principal, without creating a document. Interest is opt-in (a goodwill
     * reminder sets `withInterest: false`). See {@see DunningOptions}.
     */
    public function assessDunning(Money $money, DunningOptions $dunningOptions): DunningAssessment
    {
        return $this->dunningInterestCalculator->assess($money, $dunningOptions);
    }

    /**
     * Create a Mahnung (dunning notice) for an overdue invoice. The overdue
     * principal is the invoice's amount due (BT-115, gross); the §288
     * assessment (interest — or none, for a Kulanz reminder — plus fees and the
     * total) is stored on the Mahnung's metadata. The Mahnung is a non-tax
     * business document (a draft linked to the invoice via `source_document_id`),
     * deliberately kept out of the immutable tax record — it never alters the
     * dunned invoice.
     */
    public function dun(Document $document, DunningOptions $dunningOptions): Document
    {
        // Only a finalized invoice carries a legal claim to dun; a draft has no
        // computed totals, so dunning it would be a €0 demand.
        throw_if($document->finalized_at === null, DunningException::class, "Only a finalized document can be dunned; [{$document->number}] is not finalized.");

        $money = Money::fromMinorUnits(
            $document->amount_due ?? $document->gross_total ?? 0,
            $this->toStringValue($document->currency, 'EUR'),
        );

        $dunningAssessment = $this->dunningInterestCalculator->assess($money, $dunningOptions);

        return $this->draft(DocumentType::Mahnung, [
            'currency' => $document->currency,
            'series' => DocumentType::Mahnung->defaultSeries(),
            'seller' => $document->seller,
            'buyer' => $document->buyer,
            'source_document_id' => $document->id,
            'meta' => [
                'dunning' => $dunningAssessment->toArray(),
                'dunned_document' => $document->number,
            ],
        ]);
    }

    /**
     * Validate an e-invoice payload (CII or UBL) against the EN 16931 / XRechnung
     * business rules using the native, Java-free engine. Returns the full report.
     */
    public function validateEInvoice(string $xml): ValidationResult
    {
        return $this->eInvoiceValidator->validate($xml);
    }

    /**
     * Parse an incoming EN 16931 e-invoice (ZUGFeRD/Factur-X or XRechnung, in CII
     * or UBL syntax) into a structured {@see ParsedEInvoice}. Fulfils the B2B
     * e-invoice receiving obligation in force since 2025-01. The returned values
     * are the sender's declarations, surfaced as-is (not re-computed or trusted).
     */
    public function parseEInvoice(string $xml): ParsedEInvoice
    {
        return $this->eInvoiceReader->read($xml);
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

        // IKS segregation of duties (preventive control): may this actor cancel?
        $this->segregationPolicy->assertCanCancel($document, $this->actorResolver->resolve());

        $document->loadMissing('lines');

        $stornoLines = $document->lines->map(static fn (DocumentLine $documentLine): array => [
            'description' => $documentLine->description,
            'quantity' => '1',
            'unit' => $documentLine->unit,
            'unit_price_minor' => -$documentLine->line_net_minor,
            'price_mode' => PriceMode::Net->value,
            'tax_rate' => $documentLine->tax_rate,
            'tax_category' => $documentLine->tax_category,
        ])->values()->all();

        // Flip document-level allowances/charges so the Storno's totals are the
        // exact negation of the original (an allowance that reduced the base is
        // reversed by a charge, and vice versa). The accounting-currency rate is
        // inherited so a non-EUR Storno can still express BT-111.
        $stornoAdjustments = $this->reversedAdjustments($document);

        // Atomic Storno: the new Storno's draft+finalization, the original's flip
        // to Cancelled, and the 'cancelled' audit entry commit together. A failure
        // anywhere rolls the whole correction back, so there can be no orphan
        // Storno without its cancelled original (Storno statt Löschen).
        // The Storno reverses the supply (lines + allowances/charges), not the
        // payment: the original's paid amount (BT-113) and Skonto terms are a
        // payment-reconciliation concern and are deliberately not carried over,
        // so the Storno is a full credit (amount due = −gross) reconciled against
        // any prior payment outside this document.
        $storno = DB::transaction(function () use ($document, $reason, $stornoLines, $stornoAdjustments): Document {
            $storno = $this->draft(DocumentType::Storno, [
                'currency' => $document->currency,
                'series' => DocumentType::Storno->defaultSeries(),
                'service_date' => $document->service_date,
                'service_period_start' => $document->service_period_start,
                'service_period_end' => $document->service_period_end,
                'is_financial_sector' => $document->is_financial_sector,
                'adjustments' => $stornoAdjustments,
                'accounting_rate' => $document->accounting_rate,
                'seller' => $document->seller,
                'buyer' => $document->buyer,
                'meta' => ['reason' => $reason, 'storno_of' => $document->number],
            ], $stornoLines);

            $storno->source_document_id = $document->id;
            $storno->save();
            $this->performFinalize($storno);

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
     * Convert a pre-invoice document (Angebot, Kostenvoranschlag,
     * Leistungsnachweis) into a new invoice DRAFT of the given type, copying its
     * line items and parties forward and keeping a `source_document_id` link so
     * the audit chain (offer → contract → invoice) is reconstructable. Overrides
     * may add/replace draft attributes (e.g. `deducts` when converting to a
     * Schlussrechnung). Corrections/cancellations are NOT conversions — use
     * {@see self::cancel()}.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function convert(Document $document, DocumentType $documentType, array $overrides = []): Document
    {
        throw_unless($document->type->canConvertTo($documentType), GobdInvoiceException::class, "A [{$document->type->value}] cannot be converted to a [{$documentType->value}].");

        $document->loadMissing('lines');

        $lines = $document->lines->map(static fn (DocumentLine $documentLine): array => [
            'description' => $documentLine->description,
            'quantity' => $documentLine->quantity,
            'unit' => $documentLine->unit,
            'unit_price_minor' => $documentLine->unit_price_minor,
            'price_mode' => $documentLine->price_mode,
            'discount_minor' => $documentLine->discount_minor,
            'adjustments' => $documentLine->line_adjustments,
            'tax_rate' => $documentLine->tax_rate,
            'tax_category' => $documentLine->tax_category,
        ])->all();

        $attributes = array_merge([
            'service_date' => $document->service_date,
            'service_period_start' => $document->service_period_start,
            'service_period_end' => $document->service_period_end,
            'seller' => $document->seller,
            'buyer' => $document->buyer,
            'adjustments' => $document->document_adjustments,
            'payment_terms' => $document->payment_terms,
            'accounting_rate' => $document->accounting_rate,
            'meta' => $document->meta,
        ], $overrides);
        unset($attributes['lines']);

        // Conversion copies minor-unit amounts verbatim, so it preserves the
        // source currency (no FX); a currency override would silently mis-value
        // the lines. The source link is passed to draft() so it is set before the
        // advance-deduction cross-order guard and the DocumentDrafted event.
        $attributes['currency'] = $document->currency;
        $attributes['source_document_id'] = $document->id;

        if (! array_key_exists('documentable', $overrides) && $document->documentable_type !== null && $document->documentable_id !== null) {
            $attributes['documentable_type'] = $document->documentable_type;
            $attributes['documentable_id'] = $document->documentable_id;
        }

        return $this->draft($documentType, $attributes, $lines);
    }

    private function performFinalize(Document $document): Document
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
            $documentTotals = $this->documentTotalsCalculator->calculate($this->totalsInputFor($document));
            $this->applyTotals($document, $documentTotals);

            // Fail closed: a tax-relevant document missing a §14 Abs. 4 mandatory
            // field must not be festgeschrieben. Validate BEFORE allocating the
            // number so a rejected finalize never burns one (gapless generator).
            if (Config::boolean('gobd-invoice.content_validation', true)) {
                $this->documentContentValidator->validate($document);
            }

            // The §14 Abs. 5 double-VAT gate is a hard correctness protection and
            // runs unconditionally — it is not disabled by the field-validation
            // toggle above (which only relaxes §14 Abs. 4 completeness).
            $this->assertAdvancesDeducted($document);

            $number ??= $this->numberSequenceGenerator->next($document->type, $series, $issuedAt->year);

            $document->number = (string) $number;
            $document->series = $number->series;
            $document->year = $number->year;
            $document->sequence = $number->sequence;
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

    private function summarizeViolations(ValidationResult $validationResult): string
    {
        return implode(', ', array_map(static fn (Violation $violation): string => $violation->ruleId, $validationResult->fatals()));
    }

    /**
     * Assemble the calculator input from the document's persisted lines and its
     * canonical document-level allowances/charges, payment terms, paid amount
     * and accounting-currency rate.
     */
    private function totalsInputFor(Document $document): TotalsInput
    {
        $currency = $document->currency;

        $adjustments = [];
        foreach ($document->document_adjustments ?? [] as $spec) {
            $adjustments[] = $this->allowanceChargeFrom($spec, $currency);
        }

        $paidAmount = $document->paid_total !== null
            ? Money::fromMinorUnits($document->paid_total, $currency)
            : null;

        $advanceDeductions = [];
        foreach ($document->advance_deductions ?? [] as $spec) {
            $advanceDeductions[] = new AdvanceDeduction(
                Money::fromMinorUnits($this->toIntOrFail($spec['net_minor'] ?? 0, 'advance net'), $currency),
                Money::fromMinorUnits($this->toIntOrFail($spec['vat_minor'] ?? 0, 'advance vat'), $currency),
                isset($spec['reference']) ? $this->toStringValue($spec['reference']) : null,
                isset($spec['date']) ? $this->toStringValue($spec['date']) : null,
            );
        }

        return new TotalsInput(
            $document->lines->all(),
            $adjustments,
            $paidAmount,
            $this->paymentTermsFor($document),
            $currency,
            $this->accountingRateFor($document),
            $advanceDeductions,
        );
    }

    private function applyTotals(Document $document, DocumentTotals $documentTotals): void
    {
        $document->line_net_total = $documentTotals->lineNetTotal->minorUnits;
        $document->allowance_total = $documentTotals->allowanceTotal->minorUnits;
        $document->charge_total = $documentTotals->chargeTotal->minorUnits;
        $document->net_total = $documentTotals->netTotal->minorUnits;
        $document->vat_total = $documentTotals->vatTotal->minorUnits;
        $document->gross_total = $documentTotals->grossTotal->minorUnits;
        $document->paid_total = $documentTotals->paidAmount->minorUnits;
        $document->rounding_total = $documentTotals->roundingAmount->minorUnits;
        $document->amount_due = $documentTotals->amountDue->minorUnits;
        $document->vat_accounting_total = $documentTotals->vatAccountingTotal?->minorUnits;
        $document->advances_net_total = $documentTotals->advancesNetTotal?->minorUnits;
        $document->advances_vat_total = $documentTotals->advancesVatTotal?->minorUnits;
        $document->tax_breakdown = $this->breakdownGroups($documentTotals->taxBreakdown);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function buildLineAttributes(int $position, array $line, string $currency): array
    {
        $unitPrice = isset($line['unit_price_minor'])
            ? Money::fromMinorUnits($this->toIntOrFail($line['unit_price_minor'], 'unit_price_minor'), $currency)
            : Money::fromDecimal($this->toStringValue($line['unit_price'] ?? null, '0'), $currency);

        $quantity = $this->toStringValue($line['quantity'] ?? null, '1');
        $priceMode = PriceMode::tryFrom($this->toStringValue($line['price_mode'] ?? null, 'net')) ?? PriceMode::Net;

        $taxRate = new TaxRate(
            $this->toStringValue($line['tax_rate'] ?? null, Config::string('gobd-invoice.tax.standard_rate', '19.0')),
            TaxCategory::from($this->toStringValue($line['tax_category'] ?? null, TaxCategory::Standard->value)),
        );

        $discount = isset($line['discount_minor'])
            ? Money::fromMinorUnits($this->toIntOrFail($line['discount_minor'], 'discount_minor'), $currency)
            : Money::zero($currency);

        // Line-level allowances/charges inherit the line's tax rate; the legacy
        // flat discount is modelled as a line allowance so it folds into BT-131.
        $allowancesCharges = [];

        if ($discount->isPositive()) {
            $allowancesCharges[] = AllowanceCharge::allowance($discount, $taxRate);
        }

        $lineAdjustments = $this->normalizeAdjustments($line['adjustments'] ?? null, $currency, $taxRate);

        foreach ($lineAdjustments ?? [] as $spec) {
            $allowancesCharges[] = $this->allowanceChargeFrom($spec, $currency, $taxRate);
        }

        $lineInput = new LineInput($unitPrice, $quantity, $taxRate, $priceMode, $allowancesCharges);

        return [
            'position' => $position,
            'description' => $this->toStringValue($line['description'] ?? null),
            'quantity' => $quantity,
            'unit' => isset($line['unit']) ? $this->toStringValue($line['unit']) : null,
            'unit_price_minor' => $unitPrice->minorUnits,
            'price_mode' => $priceMode->value,
            'discount_minor' => $discount->minorUnits,
            'line_adjustments' => $lineAdjustments,
            'line_net_minor' => $lineInput->netAmount()->minorUnits,
            'tax_rate' => $taxRate->percent(),
            'tax_category' => $taxRate->categoryCode(),
            'currency' => $currency,
        ];
    }

    /**
     * Validate raw allowance/charge specs into value objects (fail loud) and
     * return them in canonical, storable form. Line-level specs inherit the
     * line's rate; document-level specs carry their own.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizeAdjustments(mixed $raw, string $currency, ?TaxRate $taxRate = null): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $specs = [];

        foreach ($raw as $item) {
            if (is_array($item)) {
                $specs[] = $this->serializeAdjustment($this->allowanceChargeFrom($item, $currency, $taxRate));
            }
        }

        return $specs === [] ? null : $specs;
    }

    /**
     * Build an allowance/charge from an arbitrary spec array (host input or the
     * stored canonical form), reading string keys defensively with defaults.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private function allowanceChargeFrom(array $spec, string $currency, ?TaxRate $inheritRate = null): AllowanceCharge
    {
        // The direction must be explicit — an unknown/typo'd type must not be
        // silently treated as an allowance (which would flip the amount's sign).
        $type = $this->toStringValue($spec['type'] ?? 'allowance');
        throw_unless(in_array($type, ['allowance', 'charge'], true), InvalidArgumentException::class, "Adjustment type must be 'allowance' or 'charge', got [{$type}].");
        $isCharge = $type === 'charge';

        $taxRate = $inheritRate ?? new TaxRate(
            $this->toStringValue($spec['tax_rate'] ?? null, Config::string('gobd-invoice.tax.standard_rate', '19.0')),
            TaxCategory::from($this->toStringValue($spec['tax_category'] ?? null, TaxCategory::Standard->value)),
        );

        $reason = isset($spec['reason']) ? $this->toStringValue($spec['reason']) : null;

        if (isset($spec['percentage'])) {
            $base = Money::fromMinorUnits($this->toIntOrFail($spec['base_minor'] ?? 0, 'base_minor'), $currency);
            $percentage = $this->toStringValue($spec['percentage']);

            return $isCharge
                ? AllowanceCharge::percentageCharge($percentage, $base, $taxRate, $reason)
                : AllowanceCharge::percentageAllowance($percentage, $base, $taxRate, $reason);
        }

        $money = Money::fromMinorUnits($this->toIntOrFail($spec['amount_minor'] ?? 0, 'amount_minor'), $currency);

        return $isCharge
            ? AllowanceCharge::charge($money, $taxRate, $reason)
            : AllowanceCharge::allowance($money, $taxRate, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAdjustment(AllowanceCharge $allowanceCharge): array
    {
        return [
            'type' => $allowanceCharge->isCharge ? 'charge' : 'allowance',
            'amount_minor' => $allowanceCharge->amount->minorUnits,
            'percentage' => $allowanceCharge->percentage,
            'base_minor' => $allowanceCharge->baseAmount?->minorUnits,
            'tax_rate' => $allowanceCharge->taxRate->percent(),
            'tax_category' => $allowanceCharge->taxRate->categoryCode(),
            'reason' => $allowanceCharge->reason,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function reversedAdjustments(Document $document): ?array
    {
        $adjustments = $document->document_adjustments;

        if ($adjustments === null || $adjustments === []) {
            return null;
        }

        $reversed = [];

        foreach ($adjustments as $adjustment) {
            $adjustment['type'] = $this->toStringValue($adjustment['type'] ?? 'allowance') === 'charge' ? 'allowance' : 'charge';
            $reversed[] = $adjustment;
        }

        return $reversed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePaymentTerms(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $paymentTerms = new PaymentTerms(
            isset($raw['net_days']) ? $this->toIntOrFail($raw['net_days'], 'net_days') : null,
            isset($raw['skonto_percentage']) ? $this->toStringValue($raw['skonto_percentage']) : null,
            isset($raw['skonto_days']) ? $this->toIntOrFail($raw['skonto_days'], 'skonto_days') : null,
            isset($raw['note']) ? $this->toStringValue($raw['note']) : null,
        );

        return [
            'net_days' => $paymentTerms->netDays,
            'skonto_percentage' => $paymentTerms->skontoPercentage,
            'skonto_days' => $paymentTerms->skontoDays,
            'note' => $paymentTerms->note,
        ];
    }

    private function paymentTermsFor(Document $document): ?PaymentTerms
    {
        $terms = $document->payment_terms;

        if ($terms === null) {
            return null;
        }

        return new PaymentTerms(
            isset($terms['net_days']) ? $this->toIntOrFail($terms['net_days'], 'net_days') : null,
            isset($terms['skonto_percentage']) ? $this->toStringValue($terms['skonto_percentage']) : null,
            isset($terms['skonto_days']) ? $this->toIntOrFail($terms['skonto_days'], 'skonto_days') : null,
            isset($terms['note']) ? $this->toStringValue($terms['note']) : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeAccountingRate(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $exchangeRate = new ExchangeRate(
            $this->toStringValue($raw['base_currency'] ?? null),
            $this->toStringValue($raw['quote_currency'] ?? null, 'EUR'),
            $this->toStringValue($raw['rate'] ?? null),
            isset($raw['reference']) ? $this->toStringValue($raw['reference']) : null,
        );

        return [
            'base_currency' => $exchangeRate->baseCurrency,
            'quote_currency' => $exchangeRate->quoteCurrency,
            'rate' => $exchangeRate->rate,
            'reference' => $exchangeRate->reference,
        ];
    }

    private function accountingRateFor(Document $document): ?ExchangeRate
    {
        $rate = $document->accounting_rate;

        if ($rate === null) {
            return null;
        }

        return new ExchangeRate(
            $this->toStringValue($rate['base_currency'] ?? null),
            $this->toStringValue($rate['quote_currency'] ?? null, 'EUR'),
            $this->toStringValue($rate['rate'] ?? null),
            isset($rate['reference']) ? $this->toStringValue($rate['reference']) : null,
        );
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
            'service_period_start' => $document->service_period_start?->toDateString(),
            'service_period_end' => $document->service_period_end?->toDateString(),
            'source_document_id' => $document->source_document_id,
            'line_net_total' => $document->line_net_total,
            'allowance_total' => $document->allowance_total,
            'charge_total' => $document->charge_total,
            'net_total' => $document->net_total,
            'vat_total' => $document->vat_total,
            'gross_total' => $document->gross_total,
            'paid_total' => $document->paid_total,
            'rounding_total' => $document->rounding_total,
            'amount_due' => $document->amount_due,
            'vat_accounting_total' => $document->vat_accounting_total,
            'advances_net_total' => $document->advances_net_total,
            'advances_vat_total' => $document->advances_vat_total,
            'tax_breakdown' => $document->tax_breakdown,
            'document_adjustments' => $document->document_adjustments,
            'payment_terms' => $document->payment_terms,
            'accounting_rate' => $document->accounting_rate,
            'advance_deductions' => $document->advance_deductions,
            'seller' => $document->seller,
            'buyer' => $document->buyer,
            'lines' => $document->lines->map(static fn (DocumentLine $documentLine): array => [
                'position' => $documentLine->position,
                'description' => $documentLine->description,
                'quantity' => $documentLine->quantity,
                'unit' => $documentLine->unit,
                'unit_price_minor' => $documentLine->unit_price_minor,
                'price_mode' => $documentLine->price_mode,
                'discount_minor' => $documentLine->discount_minor,
                'line_adjustments' => $documentLine->line_adjustments,
                'line_net_minor' => $documentLine->line_net_minor,
                'tax_rate' => $documentLine->tax_rate,
                'tax_category' => $documentLine->tax_category,
                'currency' => $documentLine->currency,
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

    /**
     * @return array<string, string|null>|null
     */
    private function normalizeParty(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return Party::fromArray($raw)->toArray();
    }

    /**
     * Resolve prior advance/progress invoices (by document id) into a canonical,
     * snapshotted deduction list, reading each advance's net + VAT AS SHOWN.
     * Fails loud if a referenced document is missing, duplicated, not a finalized
     * (non-cancelled) Abschlagsrechnung, in a different currency, or — when the
     * final invoice is linked to an order — belongs to a different order.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function resolveAdvanceDeductions(mixed $deducts, string $currency, ?string $documentableType, ?int $documentableId): ?array
    {
        if (! is_array($deducts) || $deducts === []) {
            return null;
        }

        /** @var class-string<Document> $model */
        $model = config('gobd-invoice.models.document', Document::class);

        $specs = [];
        $seen = [];

        foreach ($deducts as $deduct) {
            throw_unless(is_int($deduct) || (is_string($deduct) && ctype_digit($deduct)), GobdInvoiceException::class, 'A deducted advance must be referenced by an integer document id.');
            $id = (int) $deduct;

            throw_if(in_array($id, $seen, true), GobdInvoiceException::class, "Advance [{$id}] is deducted more than once.");
            $seen[] = $id;

            $advance = $model::query()->find($id);

            throw_unless($advance instanceof Document, GobdInvoiceException::class, "Deducted advance [{$id}] was not found.");
            throw_unless($advance->type->isAdvanceInvoice(), GobdInvoiceException::class, "Document [{$id}] is not an advance invoice (Abschlags-/Anzahlungsrechnung) and cannot be deducted in a Schlussrechnung.");
            throw_if($advance->finalized_at === null, GobdInvoiceException::class, "Deducted advance [{$id}] is not finalized.");
            throw_if($advance->status === DocumentStatus::Cancelled, GobdInvoiceException::class, "Deducted advance [{$id}] is cancelled (its VAT was already reversed by a Storno) and must not be deducted.");
            throw_if($advance->currency !== $currency, GobdInvoiceException::class, "Deducted advance [{$id}] currency [{$advance->currency}] differs from the final invoice currency [{$currency}].");

            // Guard against deducting an advance from a different order.
            if ($documentableType !== null && $documentableId !== null) {
                throw_if(
                    $advance->documentable_type !== $documentableType || $advance->documentable_id !== $documentableId,
                    GobdInvoiceException::class,
                    "Deducted advance [{$id}] belongs to a different order than the final invoice.",
                );
            }

            $specs[] = [
                'document_id' => $advance->id,
                'reference' => $advance->number,
                'date' => $advance->issue_date?->toDateString(),
                'net_minor' => $advance->net_total ?? 0,
                'vat_minor' => $advance->vat_total ?? 0,
            ];
        }

        return $specs;
    }

    /**
     * The §14 Abs. 5 double-VAT gate: a Schlussrechnung linked to an order
     * (documentable) cannot finalize while finalized Abschlagsrechnungen for that
     * order remain un-deducted — otherwise their VAT would be owed twice (§14c).
     * Without an order link the caller must deduct advances explicitly.
     */
    private function assertAdvancesDeducted(Document $document): void
    {
        if ($document->type !== DocumentType::Schlussrechnung) {
            return;
        }

        if ($document->documentable_type === null || $document->documentable_id === null) {
            return;
        }

        $deductedIds = [];
        foreach ($document->advance_deductions ?? [] as $spec) {
            if (isset($spec['document_id']) && is_numeric($spec['document_id'])) {
                $deductedIds[] = (int) $spec['document_id'];
            }
        }

        /** @var class-string<Document> $model */
        $model = config('gobd-invoice.models.document', Document::class);

        // A cancelled (Storno'd) advance had its VAT reversed by its Storno, so it
        // must NOT be deducted and does not count as undeducted here.
        $hasUndeducted = $model::query()
            ->where('documentable_type', $document->documentable_type)
            ->where('documentable_id', $document->documentable_id)
            ->whereIn('type', DocumentType::advanceInvoiceValues())
            ->whereNotNull('finalized_at')
            ->where('status', '!=', DocumentStatus::Cancelled->value)
            ->whereNotIn('id', $deductedIds)
            ->exists();

        throw_if($hasUndeducted, DocumentContentException::withViolations($document->number, ['undeducted_advances']));
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

    /**
     * Convert a host-supplied minor-unit amount to int, failing loud on a
     * non-numeric value rather than silently booking it as 0 (a wrong amount).
     */
    private function toIntOrFail(mixed $value, string $label): int
    {
        throw_unless(is_numeric($value), InvalidArgumentException::class, "{$label} must be a numeric minor-unit amount, got [{$this->toStringValue($value)}].");

        return (int) $value;
    }
}
