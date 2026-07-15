<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

function finalizedOfType(DocumentType $type): JohnWink\GobdInvoice\Models\Document
{
    return GobdInvoice::finalize(GobdInvoice::draft($type, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00'],
    ]));
}

it('classifies retention by document kind (§147 AO)', function (): void {
    $rechnung = finalizedOfType(DocumentType::Rechnung);   // tax-relevant → Buchungsbeleg
    $angebot = finalizedOfType(DocumentType::Angebot);     // not tax-relevant → correspondence

    expect($rechnung->retention_class)->toBe('voucher')
        ->and($rechnung->retention_until->year)->toBe($rechnung->issue_date->copy()->endOfYear()->addYears(8)->year)
        ->and($angebot->retention_class)->toBe('correspondence')
        ->and($angebot->retention_until->year)->toBe($angebot->issue_date->copy()->endOfYear()->addYears(6)->year);
});
