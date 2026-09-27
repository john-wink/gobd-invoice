<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

/**
 * Swap in a host Document subclass whose global scope hides every document,
 * as a tenant scope does when its context is cleared or belongs to another
 * team.
 */
function hideDocumentsBehindHostScope(): void
{
    $hidden = new class extends Document
    {
        #[Override]
        protected static function booted(): void
        {
            parent::booted();

            self::addGlobalScope('host-tenant', static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'));
        }
    };

    config()->set('gobd-invoice.models.document', $hidden::class);
}

it('blocks updating a line of a finalized document that a host scope hides', function (): void {
    $document = finalizedInvoiceWithLine();
    hideDocumentsBehindHostScope();

    $line = DocumentLine::query()->where('document_id', $document->id)->sole();
    $line->line_net_minor = 999999;

    expect(fn () => $line->save())->toThrow(DocumentIsImmutableException::class)
        ->and(DocumentLine::query()->whereKey($line->id)->value('line_net_minor'))->toBe(10000);
});

it('blocks deleting a line of a finalized document that a host scope hides', function (): void {
    $document = finalizedInvoiceWithLine();
    hideDocumentsBehindHostScope();

    $line = DocumentLine::query()->where('document_id', $document->id)->sole();

    expect(fn () => $line->delete())->toThrow(DocumentIsImmutableException::class)
        ->and(DocumentLine::query()->where('document_id', $document->id)->count())->toBe(1);
});

it('blocks changing a line whose document is gone', function (): void {
    $document = finalizedInvoiceWithLine();
    $line = DocumentLine::query()->where('document_id', $document->id)->sole();
    tamperBypassingDatabaseGuards(static function () use ($document): void {
        DB::table('gobd_documents')->where('id', $document->id)->delete();
    });

    $line->line_net_minor = 999999;

    expect(fn () => $line->save())->toThrow(DocumentIsImmutableException::class, 'cannot be read');
});

it('still allows editing the lines of a draft that a host scope hides', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00'],
    ]);
    hideDocumentsBehindHostScope();

    $line = DocumentLine::query()->where('document_id', $draft->id)->sole();
    $line->description = 'geändert';
    $line->save();

    expect($line->fresh()?->description)->toBe('geändert');
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
