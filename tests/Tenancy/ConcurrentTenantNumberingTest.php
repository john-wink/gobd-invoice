<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\Support\ParallelProcesses;
use JohnWink\GobdInvoice\Tests\TestCase;

const TENANT_PARALLEL_FINALIZATIONS_PER_TEAM = 12;

const TENANT_CONCURRENCY_RUNS = 10;

it('gives two teams finalizing at the same moment each their own gapless, duplicate-free sequence', function (): void {
    $teams = [newTenant(), newTenant()];
    $jobs = [];

    foreach ($teams as $team) {
        for ($draft = 0; $draft < TENANT_PARALLEL_FINALIZATIONS_PER_TEAM; $draft++) {
            $jobs[] = tenantDraft($team)->id;
        }
    }

    shuffle($jobs);

    $failures = ParallelProcesses::run(count($jobs), static function (int $index) use ($jobs): void {
        GobdInvoice::finalize(Document::query()->findOrFail($jobs[$index]));
    });

    expect($failures)->toBe([]);

    foreach ($teams as $team) {
        $finalized = Document::query()->where('team_id', $team)->whereNotNull('finalized_at')->orderBy('sequence')->get();

        expect($finalized->pluck('sequence')->all())->toBe(range(1, TENANT_PARALLEL_FINALIZATIONS_PER_TEAM))
            ->and($finalized->pluck('number')->unique()->count())->toBe(TENANT_PARALLEL_FINALIZATIONS_PER_TEAM);
    }
})->repeat(TENANT_CONCURRENCY_RUNS)
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
