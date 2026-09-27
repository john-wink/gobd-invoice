<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * The status invariants below the model: a raw statement, a bulk update or a
 * second application must not be able to move a status outside the positive
 * list, cancel an invoice without a Storno or festschreiben a Storno without
 * its original. Each case runs as the table owner and as a plain application
 * role, because a production host usually connects with the latter.
 */

function statusGuardedInvoice(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));
}

/**
 * @return array<string, mixed>
 */
function rawFinalization(): array
{
    return [
        'status' => DocumentStatus::Finalized->value,
        'finalized_at' => now(),
        'number' => 'STORNO-ROH',
        'series' => DocumentType::Storno->defaultSeries(),
        'year' => 2026,
        'sequence' => 1,
    ];
}

it('refuses a raw status change outside the positive list', function (string $role): void {
    $invoice = statusGuardedInvoice();
    DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Paid->value]);

    expect(fn () => asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Sent->value])))
        ->toThrow(QueryException::class, 'status');
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to set the status of a cancelled document again', function (string $role): void {
    $invoice = statusGuardedInvoice();
    GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    expect(fn () => asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value])))
        ->toThrow(QueryException::class, 'cancelled');
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to cancel a finalized invoice without a Storno', function (string $role): void {
    $invoice = statusGuardedInvoice();

    expect(fn () => asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value])))
        ->toThrow(QueryException::class, 'Storno')
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized);
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to finalize a Storno that references no document', function (string $role): void {
    $storno = GobdInvoice::draft(DocumentType::Storno, [], lineSet('-100.00'));

    expect(fn () => asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $storno->id)->update(rawFinalization())))
        ->toThrow(QueryException::class, 'Storno')
        ->and($storno->fresh()?->finalized_at)->toBeNull();
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses to finalize a Storno while its original is not cancelled', function (string $role): void {
    $invoice = statusGuardedInvoice();
    $storno = GobdInvoice::draft(DocumentType::Storno, ['source_document_id' => $invoice->id], lineSet('-100.00'));

    expect(fn () => asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $storno->id)->update(rawFinalization())))
        ->toThrow(QueryException::class, 'Storno')
        ->and($storno->fresh()?->finalized_at)->toBeNull()
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized);
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('accepts a Storno and its cancelled original written in one transaction', function (string $role): void {
    $invoice = statusGuardedInvoice();
    $storno = GobdInvoice::draft(DocumentType::Storno, ['source_document_id' => $invoice->id], lineSet('-100.00'));

    asDatabaseRole($role, static function () use ($invoice, $storno): void {
        DB::transaction(static function () use ($invoice, $storno): void {
            DB::table('gobd_documents')->where('id', $storno->id)->update(rawFinalization());
            DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value]);
        });
    });

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Cancelled)
        ->and($storno->fresh()?->finalized_at)->not->toBeNull();
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('lets the package lifecycle pass the guards', function (string $role): void {
    [$paid, $cancelled, $storno] = asDatabaseRole($role, static function (): array {
        $paid = statusGuardedInvoice();
        GobdInvoice::markSent($paid);
        GobdInvoice::recordPayment($paid, 5000);
        GobdInvoice::recordPayment($paid, (int) $paid->gross_total - 5000);

        $cancelled = statusGuardedInvoice();

        return [$paid, $cancelled, GobdInvoice::cancel($cancelled, 'Kunde hat storniert')];
    });

    expect($paid->fresh()?->status)->toBe(DocumentStatus::Paid)
        ->and($cancelled->fresh()?->status)->toBe(DocumentStatus::Cancelled)
        ->and(GobdInvoice::verify($paid->fresh()))->toBeTrue()
        ->and(GobdInvoice::verify($cancelled->fresh()))->toBeTrue()
        ->and(GobdInvoice::verify($storno->fresh()))->toBeTrue();
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses a raw change of the retention and host link of a finalized document', function (string $role, string $column, bool|int|string $value): void {
    $invoice = statusGuardedInvoice();

    expect(fn () => asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $invoice->id)->update([$column => $value])))
        ->toThrow(QueryException::class, 'immutable');
})->with('database roles')->with([
    'retention until' => ['retention_until', '2099-12-31'],
    'retention class' => ['retention_class', 'correspondence'],
    'financial sector' => ['is_financial_sector', true],
    'documentable type' => ['documentable_type', 'order'],
    'documentable id' => ['documentable_id', 7],
])->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('keeps the host metadata of a finalized document writable', function (string $role): void {
    $invoice = statusGuardedInvoice();

    asDatabaseRole($role, fn () => DB::table('gobd_documents')->where('id', $invoice->id)->update(['meta' => json_encode(['exported' => true])]));

    expect($invoice->fresh()?->meta)->toBe(['exported' => true]);
})->with('database roles')
    ->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');
