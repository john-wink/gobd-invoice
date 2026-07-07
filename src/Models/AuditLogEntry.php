<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use Override;

/**
 * An append-only audit-log row. Entries are insert-only: updating or deleting a
 * row is blocked at the model level so the log stays tamper-evident
 * (GoBD Nachvollziehbarkeit). Each entry chains to the previous via
 * `previous_hash`. See docs/research/01-gobd-compliance.md.
 *
 * @property int $id
 * @property int|null $document_id
 * @property string $event
 * @property string|null $actor
 * @property array<string, mixed>|null $context
 * @property string|null $content_hash
 * @property string|null $previous_hash
 * @property Carbon|null $created_at
 */
class AuditLogEntry extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

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

        return $this->belongsTo($model);
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
            'document_id' => 'integer',
            'context' => 'array',
        ];
    }
}
