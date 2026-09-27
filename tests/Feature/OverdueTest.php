<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * A festgeschriebene invoice falls due whether or not it was ever marked as
 * sent: Finalized → Overdue is allowed once the due date has passed.
 */

function invoiceDueOn2026January19(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [
        'issue_date' => '2026-01-05',
        'payment_terms' => ['net_days' => 14],
    ], lineSet('100.00')));
}

afterEach(function (): void {
    Date::setTestNow();
});

it('marks a festgeschriebene, never sent invoice overdue once its due date has passed', function (): void {
    $invoice = invoiceDueOn2026January19();
    Date::setTestNow('2026-01-20 09:00:00');

    GobdInvoice::markOverdue($invoice);

    expect($invoice->refresh()->status)->toBe(DocumentStatus::Overdue)
        ->and(AuditLogEntry::query()->where('document_id', $invoice->id)->orderBy('sequence')->pluck('event')->all())->toBe(['finalized', 'overdue'])
        ->and(GobdInvoice::verify($invoice))->toBeTrue();
});

it('allows the transition from finalized to overdue in the state machine', function (): void {
    expect(DocumentStatus::Finalized->canTransitionTo(DocumentStatus::Overdue))->toBeTrue()
        ->and(DocumentStatus::Overdue->canTransitionTo(DocumentStatus::Paid))->toBeTrue();
});

it('lets the database status guard pass finalized to overdue', function (string $role): void {
    $invoice = invoiceDueOn2026January19();

    asDatabaseRole($role, fn (): int => DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Overdue->value]));

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Overdue);
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('does not mark an invoice overdue on or before its due date', function (string $today): void {
    $invoice = invoiceDueOn2026January19();
    Date::setTestNow($today);

    expect(fn (): Document => GobdInvoice::markOverdue($invoice))->toThrow(GobdInvoiceException::class, 'not past its due date')
        ->and($invoice->refresh()->status)->toBe(DocumentStatus::Finalized);
})->with([
    'on the due date' => '2026-01-19 23:00:00',
    'before the due date' => '2026-01-10 09:00:00',
]);

it('does not mark a document overdue that has nothing left to pay', function (): void {
    $invoice = invoiceDueOn2026January19();
    Date::setTestNow('2026-02-01 09:00:00');
    $storno = GobdInvoice::cancel($invoice, 'doppelt', '2026-01-06');

    expect(fn (): Document => GobdInvoice::markOverdue($storno))->toThrow(GobdInvoiceException::class, 'no amount due')
        ->and($storno->refresh()->status)->toBe(DocumentStatus::Finalized);
});

it('books a payment on an overdue invoice that was never sent', function (): void {
    $invoice = invoiceDueOn2026January19();
    Date::setTestNow('2026-01-20 09:00:00');
    GobdInvoice::markOverdue($invoice);

    GobdInvoice::recordPayment($invoice, (int) $invoice->gross_total);

    expect($invoice->refresh()->status)->toBe(DocumentStatus::Paid)
        ->and($invoice->amount_due)->toBe(0);
});
