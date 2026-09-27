<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\InvalidStatusTransitionException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

it('allows only one Storno per original and team in the database', function (): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');
    $copy = (array) DB::table('gobd_documents')->where('id', $storno->id)->first();
    $copy['id'] = (string) Str::uuid7();
    $copy['number'] = 'STORNO-KOPIE';

    expect(fn () => DB::table('gobd_documents')->insert($copy))->toThrow(QueryException::class)
        ->and(DB::table('gobd_documents')->where('team_id', $team)->where('source_document_id', $invoice->id)->count())->toBe(1);
});

it('refuses to finalize a Storno against a document of another team', function (): void {
    $invoice = GobdInvoice::finalize(tenantDraft(newTenant()));
    $storno = tenantDraft(newTenant(), DocumentType::Storno, ['source_document_id' => $invoice->id], lineSet('-10.00'));

    expect(fn () => GobdInvoice::finalize($storno))->toThrow(InvalidStatusTransitionException::class)
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized)
        ->and($storno->fresh()?->finalized_at)->toBeNull();
});

it('detects an audit entry moved to another team', function (): void {
    $invoice = GobdInvoice::finalize(tenantDraft(newTenant()));
    expect(GobdInvoice::verify($invoice->fresh()))->toBeTrue();

    tamperBypassingDatabaseGuards(static function () use ($invoice): void {
        DB::table('gobd_audit_log')->where('document_id', $invoice->id)->update(['team_id' => newTenant()]);
    });

    expect(GobdInvoice::verify($invoice->fresh()))->toBeFalse();
});
