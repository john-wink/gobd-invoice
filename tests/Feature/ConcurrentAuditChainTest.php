<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\Support\ParallelProcesses;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * Twelve processes write to the audit trail of one document at the same
 * instant. The trail must stay a single chain: one end, contiguous
 * sequence numbers, and verify() still true.
 */

const PARALLEL_APPENDS = 12;

const APPEND_RUNS = 5;

it('keeps a single audit chain when twelve entries are appended in parallel', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));

    $failures = ParallelProcesses::run(PARALLEL_APPENDS, static function (int $index) use ($invoice): void {
        app(AuditLogger::class)->append(Document::query()->findOrFail($invoice->id), 'note', ['index' => $index]);
    });

    expect($failures)->toBe([])
        ->and(auditChainEnds($invoice->id))->toHaveCount(1)
        ->and(GobdInvoice::verify($invoice->fresh()))->toBeTrue()
        ->and(AuditLogEntry::query()->where('document_id', $invoice->id)->orderBy('sequence')->pluck('sequence')->all())
        ->toBe(range(1, PARALLEL_APPENDS + 1));
})->repeat(APPEND_RUNS)
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
