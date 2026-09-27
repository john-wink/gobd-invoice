<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/*
 * recordPayment() and cancel() lock the document and re-read it. A change the
 * host made on its instance and did not save would be overwritten by that
 * re-read, so they refuse instead of dropping it silently. finalize() locks
 * and re-reads the draft as well, but keeps such a change and saves it with
 * the Festschreibung, as it always has.
 */

function finalizedInvoiceWithUnsavedMeta(): Document
{
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));
    $invoice->meta = ['exported' => true];

    return $invoice;
}

it('refuses a payment on an instance with unsaved changes and keeps them', function (): void {
    $invoice = finalizedInvoiceWithUnsavedMeta();

    expect(fn () => GobdInvoice::recordPayment($invoice, 5000))->toThrow(GobdInvoiceException::class, 'unsaved changes to [meta]')
        ->and($invoice->meta)->toBe(['exported' => true])
        ->and($invoice->isDirty('meta'))->toBeTrue()
        ->and($invoice->fresh()?->paid_total)->toBe(0)
        ->and($invoice->auditEntries()->where('event', 'payment_recorded')->count())->toBe(0);
});

it('refuses to cancel an instance with unsaved changes and keeps them', function (): void {
    $invoice = finalizedInvoiceWithUnsavedMeta();

    expect(fn () => GobdInvoice::cancel($invoice, 'Kunde hat storniert'))->toThrow(GobdInvoiceException::class, 'unsaved changes to [meta]')
        ->and($invoice->meta)->toBe(['exported' => true])
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized)
        ->and(Document::query()->where('type', DocumentType::Storno->value)->count())->toBe(0);
});

it('books a payment once the host saved its change', function (): void {
    $invoice = finalizedInvoiceWithUnsavedMeta();
    $invoice->save();

    GobdInvoice::recordPayment($invoice, 5000);

    expect($invoice->fresh()?->meta)->toBe(['exported' => true])
        ->and($invoice->fresh()?->paid_total)->toBe(5000);
});

it('saves an unsaved change of a draft with its Festschreibung', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00'));
    $draft->meta = ['exported' => true];

    GobdInvoice::finalize($draft);

    $finalized = Document::query()->findOrFail($draft->id);

    expect($finalized->meta)->toBe(['exported' => true])
        ->and($finalized->status)->toBe(DocumentStatus::Finalized)
        ->and(GobdInvoice::verify($finalized))->toBeTrue();
});
