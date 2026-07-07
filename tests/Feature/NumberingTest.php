<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

function finalizedInvoice(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00'],
    ]));
}

it('assigns sequential, unique numbers at finalization', function (): void {
    $first = finalizedInvoice();
    $second = finalizedInvoice();

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($first->number)->not->toBe($second->number);
});

it('does not consume a number for a discarded draft (gapless)', function (): void {
    // A draft that is never finalized must not burn a number.
    GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00'],
    ]);

    expect(finalizedInvoice()->sequence)->toBe(1);
});

it('numbers each document type on its own series', function (): void {
    $rechnung = finalizedInvoice();
    $angebot = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00'],
    ]));

    expect($rechnung->sequence)->toBe(1)
        ->and($angebot->sequence)->toBe(1)
        ->and($rechnung->series)->toBe('rechnung')
        ->and($angebot->series)->toBe('angebot');
});

// NOTE: row-lock concurrency must additionally be proven on MySQL/Postgres in
// CI — lockForUpdate is a no-op on SQLite (see docs/research/08, B8).
it('keeps numbers unique across many finalizations', function (): void {
    $numbers = collect(range(1, 25))->map(fn (): string => (string) finalizedInvoice()->number);

    expect($numbers->unique())->toHaveCount(25);
});
