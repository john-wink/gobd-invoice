<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Enums\TaxCategory;

it('knows which document types are tax-relevant', function (): void {
    expect(DocumentType::Rechnung->isTaxRelevant())->toBeTrue()
        ->and(DocumentType::Schlussrechnung->isTaxRelevant())->toBeTrue()
        ->and(DocumentType::Storno->isTaxRelevant())->toBeTrue()
        ->and(DocumentType::Angebot->isTaxRelevant())->toBeFalse()
        ->and(DocumentType::Mahnung->isTaxRelevant())->toBeFalse();
});

it('only emits e-invoices for invoice-like types', function (): void {
    expect(DocumentType::Rechnung->canEmitEInvoice())->toBeTrue()
        ->and(DocumentType::Angebot->canEmitEInvoice())->toBeFalse()
        ->and(DocumentType::Mahnung->canEmitEInvoice())->toBeFalse();
});

it('uses valid, unique UNCL5305 tax category codes', function (): void {
    $codes = array_map(fn (TaxCategory $c): string => $c->value, TaxCategory::cases());

    expect($codes)->toBe(array_unique($codes)) // no duplicate backed values
        ->and(TaxCategory::Standard->value)->toBe('S')   // 19% AND 7% both 'S'
        ->and(TaxCategory::Exempt->value)->toBe('E')      // §19 Kleinunternehmer
        ->and(TaxCategory::ReverseCharge->value)->toBe('AE')
        ->and(TaxCategory::Standard->isTaxed())->toBeTrue()
        ->and(TaxCategory::Exempt->isTaxed())->toBeFalse();
});

it('does not derive an exemption note from the bare category', function (): void {
    // §19 vs a §4 UStG exemption both use category E, so the note is context-
    // dependent and must NOT come from the category (it is owned by the §19
    // assessment). Reverse charge, by contrast, always carries its note.
    expect(TaxCategory::Exempt->noteTranslationKey())->toBeNull()
        ->and(TaxCategory::ReverseCharge->noteTranslationKey())->toBe('gobd-invoice::gobd-invoice.notes.reverse_charge');
});

it('enforces the lifecycle state machine', function (): void {
    expect(DocumentStatus::Draft->canTransitionTo(DocumentStatus::Finalized))->toBeTrue()
        ->and(DocumentStatus::Finalized->canTransitionTo(DocumentStatus::Cancelled))->toBeTrue()
        ->and(DocumentStatus::Paid->canTransitionTo(DocumentStatus::Draft))->toBeFalse()
        ->and(DocumentStatus::Draft->isFinalized())->toBeFalse()
        ->and(DocumentStatus::Finalized->isFinalized())->toBeTrue();
});
