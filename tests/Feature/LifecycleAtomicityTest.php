<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

// failAuditOn(), restoreRealAuditLogger() and lineSet() are shared helpers in Pest.php.

it('rolls finalize back atomically: a failing audit append leaves an editable draft and burns no number', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet());

    failAuditOn('finalized');

    expect(fn () => GobdInvoice::finalize($draft))->toThrow(RuntimeException::class);

    $fresh = $draft->fresh();
    expect($fresh?->status)->toBe(DocumentStatus::Draft)
        ->and($fresh?->number)->toBeNull()
        ->and($fresh?->finalized_at)->toBeNull();

    // No gap: the next successful finalization still receives sequence 1.
    restoreRealAuditLogger();
    expect(GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet()))->sequence)->toBe(1);
});

it('rolls cancel back atomically: a failing cancellation leaves no orphan Storno and keeps the original finalized', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));

    failAuditOn('cancelled');

    expect(fn () => GobdInvoice::cancel($invoice, 'Test'))->toThrow(RuntimeException::class);

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized)
        ->and(Document::query()->where('type', DocumentType::Storno->value)->count())->toBe(0);
});
