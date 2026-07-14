<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Contracts\TaxableLine;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;
use Override;

/**
 * A single position on a document. The net amount is the already-computed line
 * total (unit price × quantity − line discount), stored in minor units.
 *
 * @property int $id
 * @property int $document_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property int $unit_price_minor
 * @property string $price_mode
 * @property int $discount_minor
 * @property array<int, array<string, mixed>>|null $line_adjustments
 * @property int $line_net_minor
 * @property string $tax_rate
 * @property string $tax_category
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentLine extends Model implements TaxableLine
{
    /** @var list<string> */
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(Config::string('gobd-invoice.table_names.lines', 'gobd_document_lines'));
    }

    public function netAmount(): Money
    {
        return Money::fromMinorUnits($this->line_net_minor, $this->currency);
    }

    public function taxRate(): TaxRate
    {
        return new TaxRate($this->tax_rate, TaxCategory::from($this->tax_category));
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        /** @var class-string<Document> $model */
        $model = config('gobd-invoice.models.document', Document::class);

        return $this->belongsTo($model);
    }

    /**
     * GoBD Unveränderbarkeit also covers line items: once the parent document is
     * finalized (festgeschrieben) its lines must not change. The parent's own
     * guards cannot see this separate table, so the line guards itself here.
     */
    #[Override]
    protected static function booted(): void
    {
        static::updating(static function (self $documentLine): void {
            $documentLine->guardAgainstImmutableParent();
        });

        static::deleting(static function (self $documentLine): void {
            $documentLine->guardAgainstImmutableParent();
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'position' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'line_adjustments' => 'array',
            'line_net_minor' => 'integer',
        ];
    }

    private function guardAgainstImmutableParent(): void
    {
        $document = $this->document;

        if ($document !== null && $document->isImmutable()) {
            throw DocumentIsImmutableException::forFinalizedDocument((string) $document->number);
        }
    }
}
