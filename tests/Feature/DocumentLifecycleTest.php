<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;

function draftInvoice(): Document
{
    return GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
        ['description' => 'Leistung B', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '7.0'],
    ]);
}

it('drafts an invoice with computed line nets and no number', function (): void {
    $document = GobdInvoice::draft(DocumentType::Rechnung, ['currency' => 'EUR'], [
        ['description' => 'Beratung', 'quantity' => '2', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect($document->status)->toBe(DocumentStatus::Draft)
        ->and($document->number)->toBeNull()
        ->and($document->lines)->toHaveCount(1)
        ->and($document->lines->first()?->line_net_minor)->toBe(20000);
});

it('finalizes a draft: number, totals, hash, status and verification', function (): void {
    $document = GobdInvoice::finalize(draftInvoice());

    expect($document->status)->toBe(DocumentStatus::Finalized)
        ->and($document->number)->not->toBeNull()
        ->and($document->net_total)->toBe(15000)
        ->and($document->vat_total)->toBe(2250)
        ->and($document->gross_total)->toBe(17250)
        ->and($document->content_hash)->not->toBeNull()
        ->and($document->finalized_at)->not->toBeNull()
        ->and(GobdInvoice::verify($document))->toBeTrue();
});

it('records an append-only audit entry on finalize', function (): void {
    $document = GobdInvoice::finalize(draftInvoice());

    $entry = AuditLogEntry::query()
        ->where('document_id', $document->id)
        ->where('event', 'finalized')
        ->firstOrFail();

    expect($entry->content_hash)->not->toBeNull();
    expect(fn () => $entry->delete())->toThrow(GobdInvoiceException::class);
});

it('blocks mutation of a finalized document (GoBD Unveränderbarkeit)', function (): void {
    $document = GobdInvoice::finalize(draftInvoice());
    $document->gross_total = 1;

    expect(fn () => $document->save())->toThrow(DocumentIsImmutableException::class);
});

it('still allows lifecycle status changes after finalization', function (): void {
    $document = GobdInvoice::finalize(draftInvoice());
    $document->status = DocumentStatus::Paid;
    $document->save();

    expect($document->fresh()?->status)->toBe(DocumentStatus::Paid);
});

it('cancels via a linked Storno instead of deleting (Storno statt Löschen)', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    expect($storno->type)->toBe(DocumentType::Storno)
        ->and($storno->source_document_id)->toBe($invoice->id)
        ->and($storno->gross_total)->toBe(-11900)
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Cancelled);
});
