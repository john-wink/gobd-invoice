<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Events\DocumentCancelled;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Exceptions\InvalidStatusTransitionException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;

/*
 * The status of a document follows DocumentStatus::allowedTransitions(), and a
 * festgeschriebenes tax-relevant document is cancelled only together with a
 * festgeschriebenen Storno that references it. The model enforces this on
 * every driver; PostgreSQL additionally in its triggers (DatabaseStatusGuardsTest).
 */

function invariantInvoice(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));
}

function stornosOf(Document $document): int
{
    return Document::query()
        ->where('source_document_id', $document->id)
        ->where('type', DocumentType::Storno->value)
        ->count();
}

it('refuses a status change outside the positive list', function (DocumentStatus $reached, DocumentStatus $target): void {
    $invoice = invariantInvoice();
    $invoice->status = $reached;
    $invoice->save();

    $invoice->status = $target;

    expect(fn () => $invoice->save())->toThrow(InvalidStatusTransitionException::class)
        ->and($invoice->fresh()?->status)->toBe($reached);
})->with([
    'paid back to sent' => [DocumentStatus::Paid, DocumentStatus::Sent],
    'paid to overdue' => [DocumentStatus::Paid, DocumentStatus::Overdue],
    'sent back to finalized' => [DocumentStatus::Sent, DocumentStatus::Finalized],
]);

it('refuses to cancel a finalized invoice without a Storno', function (): void {
    $invoice = invariantInvoice();
    $invoice->status = DocumentStatus::Cancelled;

    expect(fn () => $invoice->save())->toThrow(InvalidStatusTransitionException::class, 'Storno')
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized);
});

it('refuses to finalize a Storno that references no document', function (): void {
    $storno = GobdInvoice::draft(DocumentType::Storno, [], lineSet('-100.00'));

    expect(fn () => GobdInvoice::finalize($storno))->toThrow(InvalidStatusTransitionException::class, 'Storno')
        ->and($storno->fresh()?->finalized_at)->toBeNull()
        ->and(GobdInvoice::cancel(invariantInvoice(), 'andere')->sequence)->toBe(1);
});

it('refuses to finalize a Storno whose source is not finalized', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00'));
    $storno = GobdInvoice::draft(DocumentType::Storno, ['source_document_id' => $draft->id], lineSet('-100.00'));

    expect(fn () => GobdInvoice::finalize($storno))->toThrow(GobdInvoiceException::class)
        ->and($storno->fresh()?->finalized_at)->toBeNull()
        ->and($draft->fresh()?->status)->toBe(DocumentStatus::Draft);
});

it('cancels the original once a Storno drafted against it is finalized', function (): void {
    Event::fake([DocumentCancelled::class]);
    $invoice = invariantInvoice();
    $storno = GobdInvoice::draft(DocumentType::Storno, ['source_document_id' => $invoice->id, 'meta' => ['reason' => 'Doppelt berechnet']], lineSet('-100.00'));

    GobdInvoice::finalize($storno);

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Cancelled)
        ->and(AuditLogEntry::query()->where('document_id', $invoice->id)->where('event', 'cancelled')->sole()->context)
        ->toEqual(['storno' => $storno->number, 'reason' => 'Doppelt berechnet'])
        ->and(GobdInvoice::verify($invoice->fresh()))->toBeTrue();

    Event::assertDispatched(DocumentCancelled::class, static fn (DocumentCancelled $event): bool => $event->storno->is($storno));
});

it('refuses to cancel an invoice a second time through a stale instance', function (): void {
    $invoice = invariantInvoice();
    $stale = Document::query()->findOrFail($invoice->id);

    GobdInvoice::cancel($invoice, 'erste Stornierung');

    expect(fn () => GobdInvoice::cancel($stale, 'zweite Stornierung'))->toThrow(GobdInvoiceException::class, 'already cancelled')
        ->and(stornosOf($invoice))->toBe(1)
        ->and($stale->status)->toBe(DocumentStatus::Cancelled);
});

it('refuses to cancel a document whose status does not allow it', function (): void {
    $invoice = invariantInvoice();
    GobdInvoice::recordPayment($invoice, (int) $invoice->gross_total);

    expect(fn () => GobdInvoice::cancel($invoice, 'bezahlt'))->toThrow(InvalidStatusTransitionException::class)
        ->and(stornosOf($invoice))->toBe(0)
        ->and(GobdInvoice::cancel(invariantInvoice(), 'andere')->sequence)->toBe(1);
});

it('allows only one Storno per original in the database', function (): void {
    $invoice = invariantInvoice();
    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');
    $copy = (array) DB::table('gobd_documents')->where('id', $storno->id)->first();
    unset($copy['id']);
    $copy['number'] = 'STORNO-KOPIE';

    expect(fn () => DB::table('gobd_documents')->insert($copy))->toThrow(QueryException::class)
        ->and(stornosOf($invoice))->toBe(1);
});
