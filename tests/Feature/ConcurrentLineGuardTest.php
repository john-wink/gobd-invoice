<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\Tests\Support\ParallelProcesses;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * One connection festschreibt a draft and keeps its transaction open; a second
 * connection writes a line of that draft in the meantime. The line guard has
 * to wait for the Festschreibung and then refuse the write — reading the
 * document without a lock would still see the draft and let the line through.
 */

const LINE_GUARD_HOLD_MICROSECONDS = 1_000_000;

const LINE_GUARD_MINIMUM_WAIT_SECONDS = 0.5;

const LINE_GUARD_SIGNAL_TIMEOUT_SECONDS = 10;

function awaitSignal(string $signal): void
{
    $deadline = microtime(true) + LINE_GUARD_SIGNAL_TIMEOUT_SECONDS;

    while (! is_file($signal)) {
        throw_if(microtime(true) > $deadline, RuntimeException::class, 'The Festschreibung never started.');

        clearstatcache(true, $signal);
        Sleep::usleep(1_000);
    }
}

function writeLineOf(string $operation, int|string $documentId): void
{
    $builder = DB::table('gobd_document_lines');

    match ($operation) {
        'update' => $builder->where('document_id', $documentId)->update(['description' => 'nach der Festschreibung geändert']),
        'insert' => $builder->insert(['document_id' => $documentId, 'description' => 'nach der Festschreibung ergänzt']),
        'delete' => $builder->where('document_id', $documentId)->delete(),
        default => throw new InvalidArgumentException("Unknown line operation [{$operation}]."),
    };
}

it('makes a line write wait for an open Festschreibung and then refuses it', function (string $operation): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00'));
    $signal = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gobd-line-guard-'.bin2hex(random_bytes(8));

    $failures = ParallelProcesses::run(2, static function (int $index) use ($draft, $signal, $operation): void {
        if ($index === 0) {
            DB::transaction(static function () use ($draft, $signal): void {
                GobdInvoice::finalize(Document::query()->findOrFail($draft->id));
                touch($signal);
                Sleep::usleep(LINE_GUARD_HOLD_MICROSECONDS);
            });

            return;
        }

        awaitSignal($signal);
        $started = hrtime(true);

        try {
            writeLineOf($operation, $draft->id);
        } catch (QueryException $queryException) {
            $waited = (hrtime(true) - $started) / 1e9;

            throw_unless(str_contains($queryException->getMessage(), 'the lines of a finalized document are immutable'), $queryException);
            throw_if($waited < LINE_GUARD_MINIMUM_WAIT_SECONDS, RuntimeException::class, sprintf('The line %s was refused after %.2f s, without waiting for the Festschreibung.', $operation, $waited));

            return;
        }

        throw new RuntimeException(sprintf('The line %s went through after %.2f s while the Festschreibung was open.', $operation, (hrtime(true) - $started) / 1e9));
    });

    if (is_file($signal)) {
        unlink($signal);
    }

    $document = Document::query()->findOrFail($draft->id);

    expect($failures)->toBe([])
        ->and($document->status)->toBe(DocumentStatus::Finalized)
        ->and(DocumentLine::query()->where('document_id', $draft->id)->pluck('description')->all())->toBe(['x'])
        ->and(GobdInvoice::verify($document))->toBeTrue();
})->with(['update', 'insert', 'delete'])
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
