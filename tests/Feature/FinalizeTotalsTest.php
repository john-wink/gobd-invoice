<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

function finalizeWith(array $attributes, array $lines): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, $attributes, $lines));
}

it('persists the full EN 16931 chain on finalize', function (): void {
    $document = finalizeWith([], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect($document->line_net_total)->toBe(10000)   // BT-106
        ->and($document->allowance_total)->toBe(0)   // BT-107
        ->and($document->charge_total)->toBe(0)      // BT-108
        ->and($document->net_total)->toBe(10000)     // BT-109
        ->and($document->vat_total)->toBe(1900)      // BT-110
        ->and($document->gross_total)->toBe(11900)   // BT-112
        ->and($document->paid_total)->toBe(0)        // BT-113
        ->and($document->rounding_total)->toBe(0)    // BT-114
        ->and($document->amount_due)->toBe(11900)    // BT-115
        ->and($document->vat_accounting_total)->toBeNull()
        ->and(GobdInvoice::verify($document))->toBeTrue();
});

it('folds a document-level allowance into the finalized totals', function (): void {
    $document = finalizeWith([
        'adjustments' => [
            ['type' => 'allowance', 'amount_minor' => 2000, 'tax_rate' => '19.0', 'tax_category' => 'S', 'reason' => 'Treuerabatt'],
        ],
    ], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect($document->line_net_total)->toBe(10000)
        ->and($document->allowance_total)->toBe(2000)
        ->and($document->net_total)->toBe(8000)
        ->and($document->vat_total)->toBe(1520)
        ->and($document->gross_total)->toBe(9520)
        ->and(GobdInvoice::verify($document))->toBeTrue();
});

it('extracts the net for a gross-authored line', function (): void {
    $document = finalizeWith([], [
        ['description' => 'A', 'quantity' => '1', 'unit_price_minor' => 11900, 'price_mode' => 'gross', 'tax_rate' => '19.0'],
    ]);

    expect($document->net_total)->toBe(10000)
        ->and($document->vat_total)->toBe(1900)
        ->and($document->gross_total)->toBe(11900);
});

it('subtracts an already-paid amount from the amount due (BT-113/BT-115)', function (): void {
    $document = finalizeWith(['paid_minor' => 5000], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect($document->gross_total)->toBe(11900)
        ->and($document->paid_total)->toBe(5000)
        ->and($document->amount_due)->toBe(6900);
});

it('stores Skonto payment terms without changing the totals', function (): void {
    $document = finalizeWith([
        'payment_terms' => ['net_days' => 30, 'skonto_percentage' => '2.0', 'skonto_days' => 10],
    ], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect($document->gross_total)->toBe(11900)
        ->and($document->amount_due)->toBe(11900)
        ->and($document->payment_terms)->toMatchArray(['skonto_percentage' => '2.0', 'skonto_days' => 10]);
});

it('expresses the VAT total in EUR for a non-EUR invoice (BT-111)', function (): void {
    $document = finalizeWith([
        'currency' => 'USD',
        'accounting_rate' => ['base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.90', 'reference' => 'BMF 2026-06'],
    ], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_rate' => '19.0'],
    ]);

    expect($document->currency)->toBe('USD')
        ->and($document->vat_total)->toBe(19000)             // 190.00 USD
        ->and($document->vat_accounting_total)->toBe(17100)  // 171.00 EUR
        ->and($document->accounting_rate)->toMatchArray(['quote_currency' => 'EUR', 'rate' => '0.90'])
        ->and(GobdInvoice::verify($document))->toBeTrue();
});

it('fails loud finalizing a non-EUR invoice without an accounting rate', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, ['currency' => 'USD'], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): Document => GobdInvoice::finalize($draft))->toThrow(InvalidArgumentException::class);
});

it('blocks mutation of a finalized tax-total column (GoBD Unveränderbarkeit)', function (): void {
    $document = finalizeWith([], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    // net_total is a §14 tax column and stays frozen. (paid_total / amount_due are
    // deliberately mutable now — they track payments after Festschreibung.)
    $document->net_total = 1;

    expect(fn () => $document->save())->toThrow(DocumentIsImmutableException::class);
});

it('makes the finalized Storno source link immutable (GoBD correction trail)', function (): void {
    $invoice = finalizeWith([], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    $storno = GobdInvoice::cancel($invoice, 'Storno');
    $storno->source_document_id = 999999;

    expect(fn () => $storno->save())->toThrow(DocumentIsImmutableException::class);
});

it('rejects an unknown adjustment type instead of silently flipping the sign', function (): void {
    expect(fn (): Document => finalizeWith([
        'adjustments' => [
            ['type' => 'Charge', 'amount_minor' => 1000, 'tax_rate' => '19.0', 'tax_category' => 'S'],
        ],
    ], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a non-numeric minor amount instead of booking it as zero', function (): void {
    expect(fn (): Document => finalizeWith([
        'adjustments' => [
            ['type' => 'allowance', 'amount_minor' => '20,00', 'tax_rate' => '19.0', 'tax_category' => 'S'],
        ],
    ], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]))->toThrow(InvalidArgumentException::class);
});

it('reverses a document-level allowance in the Storno', function (): void {
    $invoice = finalizeWith([
        'adjustments' => [
            ['type' => 'allowance', 'amount_minor' => 2000, 'tax_rate' => '19.0', 'tax_category' => 'S'],
        ],
    ], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    // Original: net 8000, gross 9520. The Storno must be the exact negation.
    expect($invoice->gross_total)->toBe(9520);

    $storno = GobdInvoice::cancel($invoice, 'Storno');

    expect($storno->type)->toBe(DocumentType::Storno)
        ->and($storno->net_total)->toBe(-8000)
        ->and($storno->vat_total)->toBe(-1520)
        ->and($storno->gross_total)->toBe(-9520);
});
