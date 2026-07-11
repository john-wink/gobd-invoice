<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Database\Factories\DocumentFactory;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use Override;

/**
 * A German business document (Rechnung, Angebot, Storno, …).
 *
 * Once finalized (festgeschrieben) the tax-relevant columns are immutable
 * (GoBD Unveränderbarkeit) — the model enforces this with model-event guards.
 *
 * @property int $id
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property string|null $created_by
 * @property string|null $series
 * @property string|null $number
 * @property int|null $year
 * @property int|null $sequence
 * @property string $currency
 * @property int|null $line_net_total
 * @property int|null $allowance_total
 * @property int|null $charge_total
 * @property int|null $net_total
 * @property int|null $vat_total
 * @property int|null $gross_total
 * @property int|null $paid_total
 * @property int|null $rounding_total
 * @property int|null $amount_due
 * @property int|null $vat_accounting_total
 * @property int|null $advances_net_total
 * @property int|null $advances_vat_total
 * @property array<int, array<string, mixed>>|null $tax_breakdown
 * @property array<int, array<string, mixed>>|null $document_adjustments
 * @property array<string, mixed>|null $payment_terms
 * @property array<string, mixed>|null $accounting_rate
 * @property array<int, array<string, mixed>>|null $advance_deductions
 * @property array<string, mixed>|null $seller
 * @property array<string, mixed>|null $buyer
 * @property Carbon|null $issue_date
 * @property Carbon|null $service_date
 * @property Carbon|null $finalized_at
 * @property string|null $content_hash
 * @property array<string, mixed>|null $finalized_payload
 * @property int|null $source_document_id
 * @property string|null $documentable_type
 * @property int|null $documentable_id
 * @property string $retention_class
 * @property Carbon|null $retention_until
 * @property bool $is_financial_sector
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DocumentLine> $lines
 */
#[UseFactory(DocumentFactory::class)]
class Document extends Model implements InvoiceDocument
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(Config::string('gobd-invoice.table_names.documents', 'gobd_documents'));
    }

    public function documentType(): DocumentType
    {
        return $this->type;
    }

    public function documentStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function isImmutable(): bool
    {
        return $this->finalized_at !== null && $this->type->isImmutableOnFinalize();
    }

    /**
     * @return HasMany<DocumentLine, $this>
     */
    public function lines(): HasMany
    {
        /** @var class-string<DocumentLine> $model */
        $model = config('gobd-invoice.models.document_line', DocumentLine::class);

        // Explicit foreign key so a host subclass (e.g. a tenant-scoped Document)
        // keeps the `document_id` column instead of an inferred `<subclass>_id`.
        return $this->hasMany($model, 'document_id')->orderBy('position');
    }

    /**
     * @return HasMany<AuditLogEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        /** @var class-string<AuditLogEntry> $model */
        $model = config('gobd-invoice.models.audit_entry', AuditLogEntry::class);

        return $this->hasMany($model, 'document_id');
    }

    /**
     * The document this one corrects/cancels (Storno → original Rechnung).
     *
     * @return BelongsTo<static, $this>
     */
    public function source(): BelongsTo
    {
        // static::class so a host subclass links to its own (scoped) model.
        return $this->belongsTo(static::class, 'source_document_id');
    }

    /**
     * Optional link to a host-app model (customer, order, …).
     *
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    #[Override]
    protected static function booted(): void
    {
        static::updating(static function (self $document): void {
            if ($document->getOriginal('finalized_at') === null) {
                return; // still a draft (or being finalized now): editing is allowed
            }

            $originalType = $document->getOriginal('type');

            if ($originalType instanceof DocumentType) {
                $type = $originalType;
            } elseif (is_string($originalType)) {
                $type = DocumentType::from($originalType);
            } else {
                return;
            }

            if (! $type->isImmutableOnFinalize()) {
                return;
            }

            $changed = array_keys($document->getDirty());

            if (array_intersect(self::IMMUTABLE_COLUMNS, $changed) !== []) {
                throw DocumentIsImmutableException::forFinalizedDocument((string) $document->number);
            }
        });

        static::deleting(static function (self $document): void {
            if ($document->isImmutable()) {
                throw DocumentIsImmutableException::forFinalizedDocument((string) $document->number);
            }
        });
    }

    /**
     * Tax-relevant columns that must not change after finalization. Lifecycle
     * columns (status, payment fields) are intentionally excluded so a
     * finalized document can still move to Sent/Paid/Overdue.
     *
     * @var list<string>
     */
    private const array IMMUTABLE_COLUMNS = [
        'type', 'number', 'series', 'year', 'sequence', 'currency', 'source_document_id',
        'line_net_total', 'allowance_total', 'charge_total',
        'net_total', 'vat_total', 'gross_total',
        'paid_total', 'rounding_total', 'amount_due', 'vat_accounting_total',
        'advances_net_total', 'advances_vat_total',
        'tax_breakdown', 'document_adjustments', 'payment_terms', 'accounting_rate',
        'advance_deductions', 'seller', 'buyer',
        'issue_date', 'service_date', 'finalized_at', 'content_hash', 'finalized_payload',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'year' => 'integer',
            'sequence' => 'integer',
            'line_net_total' => 'integer',
            'allowance_total' => 'integer',
            'charge_total' => 'integer',
            'net_total' => 'integer',
            'vat_total' => 'integer',
            'gross_total' => 'integer',
            'paid_total' => 'integer',
            'rounding_total' => 'integer',
            'amount_due' => 'integer',
            'vat_accounting_total' => 'integer',
            'advances_net_total' => 'integer',
            'advances_vat_total' => 'integer',
            'tax_breakdown' => 'array',
            'document_adjustments' => 'array',
            'payment_terms' => 'array',
            'accounting_rate' => 'array',
            'advance_deductions' => 'array',
            'seller' => 'array',
            'buyer' => 'array',
            'issue_date' => 'date',
            'service_date' => 'date',
            'finalized_at' => 'datetime',
            'finalized_payload' => 'array',
            'retention_until' => 'date',
            'is_financial_sector' => 'boolean',
            'meta' => 'array',
        ];
    }
}
