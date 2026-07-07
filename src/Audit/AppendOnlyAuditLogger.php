<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Models\AuditLogEntry;

/**
 * Default {@see AuditLogger}. Appends a hash-chained, insert-only row for every
 * recorded event. Each entry's `previous_hash` points at the prior entry's
 * `content_hash`, forming a tamper-evident chain per document.
 */
final readonly class AppendOnlyAuditLogger implements AuditLogger
{
    public function __construct(
        private ContentHasher $contentHasher,
    ) {}

    public function append(InvoiceDocument $invoiceDocument, string $event, array $context = []): Model
    {
        /** @var class-string<AuditLogEntry> $model */
        $model = config('gobd-invoice.models.audit_entry', AuditLogEntry::class);

        $documentId = $invoiceDocument instanceof Model ? $invoiceDocument->getKey() : null;

        $previous = $model::query()
            ->where('document_id', $documentId)
            ->latest('id')
            ->first();

        $previousHash = $previous?->content_hash;

        $contentHash = $this->chainHash($documentId, $event, $context, $previousHash);

        return $model::create([
            'document_id' => $documentId,
            'event' => $event,
            'actor' => $this->resolveActor(),
            'context' => $context,
            'content_hash' => $contentHash,
            'previous_hash' => $previousHash,
        ]);
    }

    public function verify(InvoiceDocument $invoiceDocument): bool
    {
        /** @var class-string<AuditLogEntry> $model */
        $model = config('gobd-invoice.models.audit_entry', AuditLogEntry::class);

        $documentId = $invoiceDocument instanceof Model ? $invoiceDocument->getKey() : null;

        $previousHash = null;

        $entries = $model::query()->where('document_id', $documentId)->orderBy('id')->get()->all();

        foreach ($entries as $entry) {
            if ($entry->previous_hash !== $previousHash) {
                return false;
            }

            $expected = $this->chainHash($documentId, $entry->event, $entry->context ?? [], $previousHash);

            if ($entry->content_hash === null || ! hash_equals($entry->content_hash, $expected)) {
                return false;
            }

            $previousHash = $entry->content_hash;
        }

        return true;
    }

    /**
     * The tamper-evidence hash for one chain entry. Hashing the previous entry's
     * hash links the entries; `append()` and `verify()` MUST hash identically.
     *
     * @param  array<string, mixed>  $context
     */
    private function chainHash(mixed $documentId, string $event, array $context, ?string $previousHash): string
    {
        return $this->contentHasher->hash([
            'document_id' => $documentId,
            'event' => $event,
            'context' => $context,
            'previous_hash' => $previousHash,
        ]);
    }

    private function resolveActor(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
