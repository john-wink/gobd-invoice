<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Contracts\ActorResolver;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Database\Tenancy;
use JohnWink\GobdInvoice\Models\AuditLogEntry;

/**
 * Default {@see AuditLogger}. Appends a hash-chained, insert-only row for every
 * recorded event. Each entry's `previous_hash` points at the prior entry's
 * `content_hash` and its `sequence` names its position, forming one
 * tamper-evident chain per document.
 *
 * Appending locks the document row, so concurrent writers queue up instead of
 * forking the chain; the unique (document_id, sequence) index is the backstop.
 * The hash covers the position, the actor and the tenant of every entry.
 */
final readonly class AppendOnlyAuditLogger implements AuditLogger
{
    public function __construct(
        private ContentHasher $contentHasher,
        private ActorResolver $actorResolver,
    ) {}

    public function append(InvoiceDocument $invoiceDocument, string $event, array $context = []): Model
    {
        return DB::transaction(function () use ($invoiceDocument, $event, $context): Model {
            $model = $this->entryModel();
            $documentId = $invoiceDocument instanceof Model ? $invoiceDocument->getKey() : null;

            if ($invoiceDocument instanceof Model && $invoiceDocument->exists) {
                $invoiceDocument->newQueryWithoutScopes()->whereKey($documentId)->lockForUpdate()->first();
            }

            $previous = $model::query()
                ->where('document_id', $documentId)
                ->orderByDesc('sequence')
                ->first();

            $sequence = ($previous->sequence ?? 0) + 1;
            $previousHash = $previous?->content_hash;
            $actor = $this->actorResolver->resolve();
            $tenant = $invoiceDocument instanceof Model ? Tenancy::of($invoiceDocument) : null;

            $entry = new $model([
                'document_id' => $documentId,
                'sequence' => $sequence,
                'event' => $event,
                'actor' => $actor,
                'context' => $context,
                'content_hash' => $this->chainHash($documentId, $sequence, $event, $actor, $tenant, $context, $previousHash),
                'previous_hash' => $previousHash,
            ]);

            foreach (Tenancy::attributesFor($tenant) as $column => $value) {
                $entry->setAttribute($column, $value);
            }

            $entry->save();

            return $entry;
        });
    }

    public function verify(InvoiceDocument $invoiceDocument): bool
    {
        $documentId = $invoiceDocument instanceof Model ? $invoiceDocument->getKey() : null;

        $previousHash = null;

        $entries = $this->entryModel()::query()->where('document_id', $documentId)->orderBy('sequence')->get()->all();

        foreach ($entries as $position => $entry) {
            if ($entry->sequence !== $position + 1 || $entry->previous_hash !== $previousHash) {
                return false;
            }

            $expected = $this->chainHash($documentId, $entry->sequence, $entry->event, $entry->actor, Tenancy::of($entry), $entry->context ?? [], $previousHash);

            if ($entry->content_hash === null || ! hash_equals($entry->content_hash, $expected)) {
                return false;
            }

            $previousHash = $entry->content_hash;
        }

        return true;
    }

    /**
     * @return class-string<AuditLogEntry>
     */
    private function entryModel(): string
    {
        /** @var class-string<AuditLogEntry> $model */
        $model = config('gobd-invoice.models.audit_entry', AuditLogEntry::class);

        return $model;
    }

    /**
     * The tamper-evidence hash for one chain entry. Hashing the previous entry's
     * hash links the entries; `append()` and `verify()` MUST hash identically.
     *
     * @param  array<string, mixed>  $context
     */
    private function chainHash(mixed $documentId, int $sequence, string $event, ?string $actor, int|string|null $tenant, array $context, ?string $previousHash): string
    {
        return $this->contentHasher->hash([
            'document_id' => $documentId,
            'sequence' => $sequence,
            'event' => $event,
            'actor' => $actor,
            'tenant' => $tenant === null ? null : (string) $tenant,
            'context' => $context,
            'previous_hash' => $previousHash,
        ]);
    }
}
