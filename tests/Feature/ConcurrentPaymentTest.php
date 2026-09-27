<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\Support\ParallelProcesses;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * Twelve bank-statement imports book a payment on the same invoice at the same
 * instant. No payment may be lost: the paid total is their sum.
 */

const PARALLEL_PAYMENTS = 12;

const PAYMENT_MINOR = 1_000;

const PAYMENT_RUNS = 5;

it('adds up payments recorded in parallel', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('1000.00')));
    $gross = (int) $invoice->gross_total;

    $failures = ParallelProcesses::run(PARALLEL_PAYMENTS, static function () use ($invoice): void {
        GobdInvoice::recordPayment(Document::query()->findOrFail($invoice->id), PAYMENT_MINOR);
    });

    $paid = $invoice->fresh();

    expect($failures)->toBe([])
        ->and($paid?->paid_total)->toBe(PARALLEL_PAYMENTS * PAYMENT_MINOR)
        ->and($paid?->amount_due)->toBe($gross - PARALLEL_PAYMENTS * PAYMENT_MINOR)
        ->and($paid?->status)->toBe(DocumentStatus::PartiallyPaid)
        ->and($paid?->auditEntries()->where('event', 'payment_recorded')->count())->toBe(PARALLEL_PAYMENTS)
        ->and(GobdInvoice::verify($paid))->toBeTrue();
})->repeat(PAYMENT_RUNS)
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
