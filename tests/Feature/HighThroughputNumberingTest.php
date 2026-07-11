<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Numbering\FastSequenceGenerator;
use JohnWink\GobdInvoice\Numbering\LockingSequenceGenerator;

function useNumberingStrategy(string $strategy): void
{
    config()->set('gobd-invoice.numbering.strategy', $strategy);
    app()->forgetInstance(NumberSequenceGenerator::class);
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);
}

function finalizedFast(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet()));
}

it('binds the gapless generator by default and the fast generator when configured', function (): void {
    useNumberingStrategy('gapless');
    $gapless = app(NumberSequenceGenerator::class);
    expect($gapless)->toBeInstanceOf(LockingSequenceGenerator::class)
        ->and($gapless->allocatesWithinTransaction())->toBeTrue();

    useNumberingStrategy('fast');
    $fast = app(NumberSequenceGenerator::class);
    expect($fast)->toBeInstanceOf(FastSequenceGenerator::class)
        ->and($fast->allocatesWithinTransaction())->toBeFalse();
});

it('assigns sequential, unique numbers under the fast (high-throughput) strategy', function (): void {
    useNumberingStrategy('fast');

    $first = finalizedFast();
    $second = finalizedFast();
    $third = finalizedFast();

    expect([$first->sequence, $second->sequence, $third->sequence])->toBe([1, 2, 3])
        ->and(collect([$first->number, $second->number, $third->number])->unique())->toHaveCount(3);
});

it('FastSequenceGenerator increments atomically per (type, series, year)', function (): void {
    $generator = new FastSequenceGenerator;

    $n1 = $generator->next(DocumentType::Rechnung, 'rechnung', 2026);
    $n2 = $generator->next(DocumentType::Rechnung, 'rechnung', 2026);
    $other = $generator->next(DocumentType::Angebot, 'angebot', 2026);

    expect($n1->sequence)->toBe(1)
        ->and($n2->sequence)->toBe(2)
        ->and($other->sequence)->toBe(1);
});

it('keeps an independent counter per document_type, series AND year', function (string $generatorClass): void {
    /** @var NumberSequenceGenerator $generator */
    $generator = new $generatorClass;

    // Baseline counter for (Rechnung, "A", 2024).
    expect($generator->next(DocumentType::Rechnung, 'A', 2024)->sequence)->toBe(1)
        ->and($generator->next(DocumentType::Rechnung, 'A', 2024)->sequence)->toBe(2)
        // Vary exactly ONE key at a time — each must start its own counter at 1.
        // If any key were dropped from the counter lookup, one of these would
        // collide with the baseline and continue at 3 instead.
        ->and($generator->next(DocumentType::Rechnung, 'A', 2025)->sequence)->toBe(1)  // year
        ->and($generator->next(DocumentType::Rechnung, 'B', 2024)->sequence)->toBe(1)  // series
        ->and($generator->next(DocumentType::Angebot, 'A', 2024)->sequence)->toBe(1);  // type
})->with([
    'fast' => [FastSequenceGenerator::class],
    'gapless' => [LockingSequenceGenerator::class],
]);

it('tolerates gaps under the fast strategy: a failed finalize burns the number', function (): void {
    useNumberingStrategy('fast');
    expect(finalizedFast()->sequence)->toBe(1);

    // Force the finalize transaction to fail AFTER the number was allocated.
    failAuditOn('finalized');

    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet());
    expect(fn () => GobdInvoice::finalize($draft))->toThrow(RuntimeException::class);

    // The fast strategy committed sequence 2 up front, so it is now a tolerated
    // gap and the next success receives sequence 3 (contrast the gapless default).
    restoreRealAuditLogger();
    useNumberingStrategy('fast');
    expect(finalizedFast()->sequence)->toBe(3);
});
