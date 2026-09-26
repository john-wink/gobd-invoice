<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\DocumentLine;

/*
 * A host may keep its own columns on the lines (e.g. the catalogue item and the
 * moment its price was frozen). The line model names them; draft() stores them
 * and convert()/cancel() carry them forward, so they are frozen with the line.
 */

beforeEach(function (): void {
    Schema::table('gobd_document_lines', static function (Blueprint $table): void {
        $table->string('catalog_reference')->nullable();
    });

    config()->set('gobd-invoice.models.document_line', PassthroughLine::class);
});

final class PassthroughLine extends DocumentLine
{
    public static function passthroughAttributes(): array
    {
        return ['catalog_reference'];
    }
}

it('stores the host attributes the line model declares and ignores all others', function (): void {
    $document = GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Fliesen', 'quantity' => '1', 'unit_price' => '10.00', 'catalog_reference' => 'tile-60x60', 'line_net_minor' => 1],
    ]);

    $line = $document->lines->sole();

    expect($line->getAttribute('catalog_reference'))->toBe('tile-60x60')
        ->and($line->line_net_minor)->toBe(1000);
});

it('carries the host attributes into a converted draft and into a Storno', function (): void {
    $angebot = GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'Fliesen', 'quantity' => '1', 'unit_price' => '10.00', 'catalog_reference' => 'tile-60x60'],
    ]);
    $invoice = GobdInvoice::finalize(GobdInvoice::convert($angebot, DocumentType::Rechnung));
    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    expect($invoice->lines->sole()->getAttribute('catalog_reference'))->toBe('tile-60x60')
        ->and($storno->lines->sole()->getAttribute('catalog_reference'))->toBe('tile-60x60');
});
