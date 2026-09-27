<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;

/*
 * The audit trail of a document is one hash chain in a fixed order: every
 * entry carries its position (`sequence`), the database refuses a second
 * entry at the same position, and the hash covers who wrote the entry.
 */

function chainedInvoice(): Document
{
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));
    GobdInvoice::markSent($invoice);
    GobdInvoice::markOverdue($invoice);

    return $invoice;
}

it('numbers the audit entries of a document in chain order', function (): void {
    $invoice = chainedInvoice();

    $entries = AuditLogEntry::query()->where('document_id', $invoice->id)->orderBy('sequence')->get();

    expect($entries->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($entries->pluck('event')->all())->toBe(['finalized', 'sent', 'overdue'])
        ->and($entries[0]->previous_hash)->toBeNull()
        ->and($entries[1]->previous_hash)->toBe($entries[0]->content_hash)
        ->and($entries[2]->previous_hash)->toBe($entries[1]->content_hash)
        ->and(auditChainEnds($invoice->id))->toBe([$entries[2]->content_hash]);
});

it('refuses a second audit entry at the same position of a chain', function (): void {
    $invoice = chainedInvoice();
    $copy = (array) DB::table('gobd_audit_log')->where('document_id', $invoice->id)->where('sequence', 3)->first();
    unset($copy['id']);
    $copy['event'] = 'forked';

    expect(fn () => DB::table('gobd_audit_log')->insert($copy))->toThrow(QueryException::class);
});

it('detects a tampered actor or position in the audit chain', function (string $column, int|string $value): void {
    $invoice = chainedInvoice();
    expect(GobdInvoice::verify($invoice->fresh()))->toBeTrue();

    tamperBypassingDatabaseGuards(static function () use ($invoice, $column, $value): void {
        DB::table('gobd_audit_log')->where('document_id', $invoice->id)->where('event', 'sent')->update([$column => $value]);
    });

    expect(GobdInvoice::verify($invoice->fresh()))->toBeFalse();
})->with([
    'actor' => ['actor', 'someone-else'],
    'position' => ['sequence', 7],
]);
