<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * The deferred Storno guard under row level security. A host that resets its
 * tenant context before the commit (or switches it to another team) hides the
 * rows from the session at the moment the guard runs. The guard must then
 * refuse, never wave the write through.
 */

dataset('context left before the commit', [
    'context cleared' => static fn (): ?string => null,
    'context of another team' => static fn (): string => newTenant(),
]);

/**
 * @return array<string, mixed>
 */
function rowSecurityStornoFinalization(): array
{
    return [
        'status' => DocumentStatus::Finalized->value,
        'finalized_at' => now(),
        'number' => 'STORNO-RLS',
        'series' => DocumentType::Storno->defaultSeries(),
        'year' => 2026,
        'sequence' => 1,
    ];
}

/**
 * Write in one transaction under the tenant's context, then leave that
 * context before the commit — as a host's runFor() inside a wrapping
 * transaction does.
 *
 * @param  Closure(): void  $writes
 */
function writeThenLeaveTenantContext(string $tenant, ?string $leaveTo, Closure $writes): void
{
    DB::transaction(static function () use ($tenant, $leaveTo, $writes): void {
        enterTenantContext($tenant);
        $writes();
        enterTenantContext($leaveTo);
    });
}

it('refuses to cancel a finalized invoice without a Storno when the context is left before the commit', function (string $role, Closure $leaveTo): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));

    expect(fn () => underTenantRowSecurity($role, fn () => writeThenLeaveTenantContext($team, $leaveTo(), static function () use ($invoice): void {
        DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value]);
    })))->toThrow(PDOException::class, 'Storno')
        ->and(Document::query()->find($invoice->id)?->status)->toBe(DocumentStatus::Finalized);
})->with('row security roles')->with('context left before the commit')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to finalize a Storno without a source when the context is left before the commit', function (string $role, Closure $leaveTo): void {
    $team = newTenant();
    $storno = tenantDraft($team, DocumentType::Storno, [], lineSet('-10.00'));

    expect(fn () => underTenantRowSecurity($role, fn () => writeThenLeaveTenantContext($team, $leaveTo(), static function () use ($storno): void {
        DB::table('gobd_documents')->where('id', $storno->id)->update(rowSecurityStornoFinalization());
    })))->toThrow(PDOException::class, 'Storno')
        ->and(Document::query()->find($storno->id)?->finalized_at)->toBeNull();
})->with('row security roles')->with('context left before the commit')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to finalize a Storno while its original is not cancelled when the context is left before the commit', function (string $role, Closure $leaveTo): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $storno = tenantDraft($team, DocumentType::Storno, ['source_document_id' => $invoice->id], lineSet('-10.00'));

    expect(fn () => underTenantRowSecurity($role, fn () => writeThenLeaveTenantContext($team, $leaveTo(), static function () use ($storno): void {
        DB::table('gobd_documents')->where('id', $storno->id)->update(rowSecurityStornoFinalization());
    })))->toThrow(PDOException::class, 'Storno')
        ->and(Document::query()->find($storno->id)?->finalized_at)->toBeNull()
        ->and(Document::query()->find($invoice->id)?->status)->toBe(DocumentStatus::Finalized);
})->with('row security roles')->with('context left before the commit')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses even a correct Storno pair when the context is left before the commit', function (string $role, Closure $leaveTo): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $storno = tenantDraft($team, DocumentType::Storno, ['source_document_id' => $invoice->id], lineSet('-10.00'));

    expect(fn () => underTenantRowSecurity($role, fn () => writeThenLeaveTenantContext($team, $leaveTo(), static function () use ($invoice, $storno): void {
        DB::table('gobd_documents')->where('id', $storno->id)->update(rowSecurityStornoFinalization());
        DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value]);
    })))->toThrow(PDOException::class, 'Storno')
        ->and(Document::query()->find($storno->id)?->finalized_at)->toBeNull()
        ->and(Document::query()->find($invoice->id)?->status)->toBe(DocumentStatus::Finalized);
})->with('row security roles')->with('context left before the commit')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('accepts a Storno pair committed inside the tenant context', function (string $role): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $storno = tenantDraft($team, DocumentType::Storno, ['source_document_id' => $invoice->id], lineSet('-10.00'));

    underTenantRowSecurity($role, static function () use ($team, $invoice, $storno): void {
        enterTenantContext($team);
        DB::transaction(static function () use ($invoice, $storno): void {
            DB::table('gobd_documents')->where('id', $storno->id)->update(rowSecurityStornoFinalization());
            DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value]);
        });
    });

    expect(Document::query()->find($invoice->id)?->status)->toBe(DocumentStatus::Cancelled)
        ->and(Document::query()->find($storno->id)?->finalized_at)->not->toBeNull();
})->with('row security roles')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('cancels through the package inside the tenant context', function (string $role): void {
    $team = newTenant();
    $cancelled = GobdInvoice::finalize(tenantDraft($team));
    $stornoed = GobdInvoice::finalize(tenantDraft($team));

    [$storno, $drafted] = underTenantRowSecurity($role, static function () use ($team, $cancelled, $stornoed): array {
        enterTenantContext($team);

        $storno = GobdInvoice::cancel($cancelled, 'Kunde hat storniert');
        $drafted = GobdInvoice::finalize(tenantDraft($team, DocumentType::Storno, ['source_document_id' => $stornoed->id], lineSet('-10.00')));

        return [$storno, $drafted];
    });

    expect(Document::query()->find($cancelled->id)?->status)->toBe(DocumentStatus::Cancelled)
        ->and(Document::query()->find($stornoed->id)?->status)->toBe(DocumentStatus::Cancelled)
        ->and(Document::query()->find($storno->id)?->finalized_at)->not->toBeNull()
        ->and(Document::query()->find($drafted->id)?->finalized_at)->not->toBeNull();
})->with('row security roles')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');
