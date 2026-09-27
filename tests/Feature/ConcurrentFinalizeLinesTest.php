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
 * A Festschreibung that waits for the number counter must already hold its
 * document: a line written by another connection in that wait either lands
 * before the lines are read or waits and is refused. Reading the lines before
 * the document is locked would snapshot a line that changes afterwards, and
 * verify() would fail on the festgeschriebene document.
 */

const COUNTER_HOLD_MICROSECONDS = 1_000_000;

const FINALIZE_LINES_MINIMUM_WAIT_SECONDS = 0.5;

function awaitWaitingOnLock(): void
{
    $deadline = microtime(true) + SIGNAL_TIMEOUT_SECONDS;

    while (! DB::table('pg_stat_activity')->whereRaw('datname = current_database()')->where('wait_event_type', 'Lock')->exists()) {
        throw_if(microtime(true) > $deadline, RuntimeException::class, 'The Festschreibung never waited for the counter.');

        Sleep::usleep(1_000);
    }
}

it('keeps a line written while the Festschreibung waits for the counter out of the festgeschriebene document', function (string $operation): void {
    GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('50.00')));

    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00'));
    $signals = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gobd-finalize-lines-'.bin2hex(random_bytes(8));
    $counterHeld = $signals.'-counter-held';
    $lineWriting = $signals.'-line-writing';

    $failures = ParallelProcesses::run(3, static function (int $index) use ($draft, $counterHeld, $lineWriting, $operation): void {
        if ($index === 0) {
            DB::transaction(static function () use ($counterHeld, $lineWriting): void {
                DB::table('gobd_number_sequences')->lockForUpdate()->get();
                touch($counterHeld);
                awaitSignal($lineWriting, 'The line write never started.');
                Sleep::usleep(COUNTER_HOLD_MICROSECONDS);
            });

            return;
        }

        awaitSignal($counterHeld, 'The counter was never held.');

        if ($index === 1) {
            GobdInvoice::finalize(Document::query()->findOrFail($draft->id));

            return;
        }

        awaitWaitingOnLock();
        touch($lineWriting);
        $started = hrtime(true);

        try {
            writeLineOf($operation, $draft->id);
        } catch (QueryException $queryException) {
            $waited = (hrtime(true) - $started) / 1e9;

            throw_unless(str_contains($queryException->getMessage(), 'the lines of a finalized document are immutable'), $queryException);
            throw_if($waited < FINALIZE_LINES_MINIMUM_WAIT_SECONDS, RuntimeException::class, sprintf('The line %s was refused after %.2f s, without waiting for the Festschreibung.', $operation, $waited));

            return;
        }

        throw new RuntimeException(sprintf('The line %s went through after %.2f s while the Festschreibung waited for the counter.', $operation, (hrtime(true) - $started) / 1e9));
    });

    array_map(unlink(...), array_filter([$counterHeld, $lineWriting], is_file(...)));

    $document = Document::query()->findOrFail($draft->id);

    expect($failures)->toBe([])
        ->and($document->status)->toBe(DocumentStatus::Finalized)
        ->and(DocumentLine::query()->where('document_id', $draft->id)->pluck('description')->all())->toBe(['x'])
        ->and(GobdInvoice::verify($document))->toBeTrue();
})->with(['update', 'insert', 'delete'])
    ->skip(! TestCase::usesPostgres(), 'Row-lock contention needs a real PostgreSQL server.');
