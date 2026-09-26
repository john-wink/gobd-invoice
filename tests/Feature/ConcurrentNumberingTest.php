<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\Support\ParallelProcesses;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * Row locks only exist on a real server: SQLite serializes every write and
 * ignores lockForUpdate(), so these tests prove nothing there and run only
 * against PostgreSQL (GOBD_TEST_DB_DRIVER=pgsql, the postgres CI job).
 */

const PARALLEL_FINALIZATIONS = 24;

const CONCURRENCY_RUNS = 10;

function selectNumberingStrategy(string $strategy): void
{
    config()->set('gobd-invoice.numbering.strategy', $strategy);
    app()->forgetInstance(NumberSequenceGenerator::class);
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);
}

it('gives parallel finalizations of one counter a gapless, duplicate-free sequence', function (string $strategy): void {
    selectNumberingStrategy($strategy);

    $ids = [];

    for ($draft = 0; $draft < PARALLEL_FINALIZATIONS; $draft++) {
        $ids[] = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet())->id;
    }

    $failures = ParallelProcesses::run(PARALLEL_FINALIZATIONS, static function (int $index) use ($ids): void {
        GobdInvoice::finalize(Document::query()->findOrFail($ids[$index]));
    });

    $finalized = Document::query()->whereIn('id', $ids)->whereNotNull('finalized_at')->orderBy('sequence')->get();

    expect($failures)->toBe([])
        ->and($finalized->pluck('sequence')->all())->toBe(range(1, PARALLEL_FINALIZATIONS))
        ->and($finalized->pluck('number')->unique()->count())->toBe(PARALLEL_FINALIZATIONS);
})->with(['gapless', 'fast'])
    ->repeat(CONCURRENCY_RUNS)
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
