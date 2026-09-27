<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Tests\TestCase;

it('refuses to move a row of any package table to another tenant', function (string $table, string $key): void {
    $document = GobdInvoice::finalize(tenantDraft(newTenant()));

    expect(fn () => DB::table($table)->where($key, $document->id)->update(['team_id' => newTenant()]))
        ->toThrow(QueryException::class, 'tenant');
})->with([
    'documents' => ['gobd_documents', 'id'],
    'lines' => ['gobd_document_lines', 'document_id'],
])->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to move a counter to another tenant', function (): void {
    GobdInvoice::finalize(tenantDraft(newTenant()));

    expect(fn () => DB::table('gobd_number_sequences')->update(['team_id' => newTenant()]))
        ->toThrow(QueryException::class, 'tenant');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses a line whose tenant differs from its document', function (): void {
    $draft = tenantDraft(newTenant());
    $line = (array) DB::table('gobd_document_lines')->where('document_id', $draft->id)->first();
    $line['id'] = (string) Illuminate\Support\Str::uuid7();
    $line['team_id'] = newTenant();

    expect(fn () => DB::table('gobd_document_lines')->insert($line))
        ->toThrow(QueryException::class, 'tenant');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses a raw update of a finalized document in the tenant configuration', function (): void {
    $document = GobdInvoice::finalize(tenantDraft(newTenant()));

    expect(fn () => DB::table('gobd_documents')->where('id', $document->id)->update(['gross_total' => 1]))
        ->toThrow(QueryException::class, 'immutable');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');
