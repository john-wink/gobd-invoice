<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

function integrityInvoice(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung', 'quantity' => '2', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));
}

it('verifies an untouched finalized document', function (): void {
    expect(GobdInvoice::verify(integrityInvoice()))->toBeTrue();
});

it('detects line tampering performed directly at the database level (bypassing the model guard)', function (): void {
    $document = integrityInvoice();
    expect(GobdInvoice::verify($document))->toBeTrue();

    DB::table('gobd_document_lines')
        ->where('document_id', $document->id)
        ->update(['line_net_minor' => 999_999]);

    expect(GobdInvoice::verify($document->fresh()))->toBeFalse();
});

it('detects audit-chain tampering', function (): void {
    $document = integrityInvoice();
    expect(GobdInvoice::verify($document))->toBeTrue();

    DB::table('gobd_audit_log')
        ->where('document_id', $document->id)
        ->update(['event' => 'tampered']);

    expect(GobdInvoice::verify($document->fresh()))->toBeFalse();
});
