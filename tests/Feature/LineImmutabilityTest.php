<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;

function finalizedInvoiceWithLine(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));
}

it('blocks updating a line of a finalized document (GoBD Unveränderbarkeit)', function (): void {
    $document = finalizedInvoiceWithLine();

    /** @var DocumentLine $line */
    $line = $document->lines()->firstOrFail();
    $line->line_net_minor = 999999;

    expect(fn () => $line->save())->toThrow(DocumentIsImmutableException::class);
});

it('blocks deleting a line of a finalized document', function (): void {
    $document = finalizedInvoiceWithLine();

    /** @var DocumentLine $line */
    $line = $document->lines()->firstOrFail();

    expect(fn () => $line->delete())->toThrow(DocumentIsImmutableException::class);
});

it('still allows editing lines while the document is a draft', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00'],
    ]);

    /** @var DocumentLine $line */
    $line = $draft->lines()->firstOrFail();
    $line->description = 'geändert';
    $line->save();

    expect($line->fresh()?->description)->toBe('geändert');
});
