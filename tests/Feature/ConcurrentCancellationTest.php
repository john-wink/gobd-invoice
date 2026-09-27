<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\Support\ParallelProcesses;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * Twelve office workers press "stornieren" on the same invoice at the same
 * instant. Exactly one Storno may come out of it; the others are refused
 * without burning a Storno number.
 */

const PARALLEL_CANCELLATIONS = 12;

const CANCELLATION_RUNS = 5;

it('issues exactly one Storno when the same invoice is cancelled in parallel', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));

    $failures = ParallelProcesses::run(PARALLEL_CANCELLATIONS, static function () use ($invoice): void {
        GobdInvoice::cancel(Document::query()->findOrFail($invoice->id), 'parallel storniert');
    });

    $stornos = Document::query()
        ->where('source_document_id', $invoice->id)
        ->where('type', DocumentType::Storno->value)
        ->get();

    expect($stornos)->toHaveCount(1)
        ->and($failures)->toHaveCount(PARALLEL_CANCELLATIONS - 1)
        ->and(array_filter($failures, static fn (string $failure): bool => ! str_contains($failure, 'already cancelled')))->toBe([])
        ->and($stornos->sole()->sequence)->toBe(1)
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Cancelled)
        ->and(GobdInvoice::verify($invoice->fresh()))->toBeTrue()
        ->and(GobdInvoice::verify($stornos->sole()))->toBeTrue();
})->repeat(CANCELLATION_RUNS)
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
