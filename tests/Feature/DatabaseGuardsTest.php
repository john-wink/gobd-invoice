<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * The model guards only protect writes that go through Eloquent. On
 * PostgreSQL the package additionally installs guard triggers, so a raw
 * statement, a bulk update or a second application cannot alter a
 * festgeschriebenes document either.
 */

function guardedInvoice(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));
}

it('refuses a raw update of a tax-relevant column of a finalized document', function (string $column, int|string $value): void {
    $document = guardedInvoice();

    expect(fn () => DB::table('gobd_documents')->where('id', $document->id)->update([$column => $value]))
        ->toThrow(QueryException::class, 'immutable');
})->with([
    'gross total' => ['gross_total', 1],
    'number' => ['number', 'RECHNUNG-X'],
    'currency' => ['currency', 'USD'],
    'content hash' => ['content_hash', 'forged'],
])->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to un-finalize a document by resetting its status to draft', function (): void {
    $document = guardedInvoice();

    expect(fn () => DB::table('gobd_documents')->where('id', $document->id)->update(['status' => DocumentStatus::Draft->value]))
        ->toThrow(QueryException::class);
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('still lets the payment lifecycle of a finalized document move on', function (): void {
    $document = guardedInvoice();

    DB::table('gobd_documents')->where('id', $document->id)->update(['status' => DocumentStatus::Paid->value, 'paid_total' => 11900, 'amount_due' => 0]);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Paid);
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses a raw delete of a finalized document', function (): void {
    $document = guardedInvoice();

    expect(fn () => DB::table('gobd_documents')->where('id', $document->id)->delete())
        ->toThrow(QueryException::class, 'finalized');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to add, change or remove a line of a finalized document', function (): void {
    $document = guardedInvoice();
    $line = (array) DB::table('gobd_document_lines')->where('document_id', $document->id)->first();
    unset($line['id']);

    expect(fn () => DB::table('gobd_document_lines')->insert($line))->toThrow(QueryException::class, 'finalized')
        ->and(fn () => DB::table('gobd_document_lines')->where('document_id', $document->id)->update(['line_net_minor' => 1]))->toThrow(QueryException::class, 'finalized')
        ->and(fn () => DB::table('gobd_document_lines')->where('document_id', $document->id)->delete())->toThrow(QueryException::class, 'finalized');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('keeps the lines of a draft editable', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet());

    DB::table('gobd_document_lines')->where('document_id', $draft->id)->update(['description' => 'geändert']);

    expect($draft->lines()->value('description'))->toBe('geändert');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to update, delete or truncate the audit log', function (): void {
    $document = guardedInvoice();

    expect(fn () => DB::table('gobd_audit_log')->where('document_id', $document->id)->update(['event' => 'tampered']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('gobd_audit_log')->where('document_id', $document->id)->delete())->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::statement('TRUNCATE gobd_audit_log'))->toThrow(QueryException::class);
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to turn a counter back or to delete it', function (): void {
    guardedInvoice();
    guardedInvoice();

    expect(fn () => DB::table('gobd_number_sequences')->update(['current_value' => 1]))->toThrow(QueryException::class, 'backwards')
        ->and(fn () => DB::table('gobd_number_sequences')->delete())->toThrow(QueryException::class);

    DB::table('gobd_number_sequences')->update(['current_value' => 5]);

    expect(guardedInvoice()->sequence)->toBe(6);
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to truncate the documents or their lines', function (string $table): void {
    guardedInvoice();

    expect(fn () => DB::statement("TRUNCATE {$table} CASCADE"))->toThrow(QueryException::class);
})->with(['gobd_documents', 'gobd_document_lines'])
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');
