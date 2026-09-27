<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Enums\KeyType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Models\Concerns\HasConfiguredKey;
use Override;

/**
 * An append-only audit-log row. Entries are insert-only: updating or deleting a
 * row is blocked at the model level so the log stays tamper-evident
 * (GoBD Nachvollziehbarkeit). Each entry chains to the previous via
 * `previous_hash`, at the position `sequence` (1, 2, … per document). See
 * docs/research/01-gobd-compliance.md.
 *
 * @property int|string $id
 * @property int|string|null $document_id
 * @property int $sequence
 * @property string $event
 * @property string|null $actor
 * @property array<string, mixed>|null $context
 * @property string|null $content_hash
 * @property string|null $previous_hash
 * @property CarbonInterface|null $created_at
 */
#[Unguarded]
class AuditLogEntry extends Model
{
    use HasConfiguredKey;

    public const UPDATED_AT = null;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(Config::string('gobd-invoice.table_names.audit_log', 'gobd_audit_log'));
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        /** @var class-string<Document> $model */
        $model = config('gobd-invoice.models.document', Document::class);

        return $this->belongsTo($model, 'document_id');
    }

    #[Override]
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new GobdInvoiceException('The audit log is append-only; entries cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new GobdInvoiceException('The audit log is append-only; entries cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'document_id' => KeyType::configured()->cast(),
            'sequence' => 'integer',
            'context' => 'array',
        ];
    }
}
