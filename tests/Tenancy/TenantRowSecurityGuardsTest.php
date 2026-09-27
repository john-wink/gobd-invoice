<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * Every guard under row level security. A session whose tenant context is
 * cleared or points at another team does not see the document. A guard that
 * reads the document must then refuse, never wave the write through; a guard
 * that only looks at the written row must refuse regardless of the context.
 */

dataset('context that hides the document', [
    'context cleared' => static fn (string $team): ?string => null,
    'context of another team' => static fn (string $team): string => newTenant(),
]);

dataset('any tenant context', [
    'context cleared' => static fn (string $team): ?string => null,
    'context of another team' => static fn (string $team): string => newTenant(),
    'context of its own team' => static fn (string $team): string => $team,
]);

/**
 * @template TResult
 *
 * @param  Closure(): TResult  $work
 * @return TResult
 */
function inTenantContext(string $role, ?string $context, Closure $work): mixed
{
    return underTenantRowSecurity($role, static function () use ($context, $work): mixed {
        enterTenantContext($context);

        return $work();
    });
}

/**
 * @return array<string, mixed>
 */
function lineRowOf(Document $document): array
{
    return (array) DB::table('gobd_document_lines')->where('document_id', $document->id)->sole();
}

function lineCountOf(Document $document): int
{
    return DB::table('gobd_document_lines')->where('document_id', $document->id)->count();
}

it('refuses to change a line of a finalized document in any tenant context', function (string $role, Closure $context): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $before = lineRowOf($invoice);

    expect(fn () => inTenantContext($role, $context($team), static function () use ($invoice): void {
        DB::table('gobd_document_lines')->where('document_id', $invoice->id)->update(['line_net_minor' => 1]);
    }))->toThrow(PDOException::class, 'gobd-invoice')
        ->and(lineRowOf($invoice))->toBe($before);
})->with('row security roles')->with('any tenant context')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to delete a line of a finalized document in any tenant context', function (string $role, Closure $context): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));

    expect(fn () => inTenantContext($role, $context($team), static function () use ($invoice): void {
        DB::table('gobd_document_lines')->where('document_id', $invoice->id)->delete();
    }))->toThrow(PDOException::class, 'immutable')
        ->and(lineCountOf($invoice))->toBe(1);
})->with('row security roles')->with('any tenant context')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to add a line to a finalized document in any tenant context', function (string $role, Closure $context): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $added = [...lineRowOf($invoice), 'id' => newTenant(), 'position' => 2];

    expect(fn () => inTenantContext($role, $context($team), static function () use ($added): void {
        DB::table('gobd_document_lines')->insert($added);
    }))->toThrow(PDOException::class, 'gobd-invoice')
        ->and(lineCountOf($invoice))->toBe(1);
})->with('row security roles')->with('any tenant context')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to change or delete a line of a draft it cannot see', function (string $role, Closure $context): void {
    $team = newTenant();
    $draft = tenantDraft($team);
    $before = lineRowOf($draft);

    expect(fn () => inTenantContext($role, $context($team), static function () use ($draft): void {
        DB::table('gobd_document_lines')->where('document_id', $draft->id)->update(['description' => 'changed']);
    }))->toThrow(PDOException::class, 'gobd-invoice')
        ->and(fn () => inTenantContext($role, $context($team), static function () use ($draft): void {
            DB::table('gobd_document_lines')->where('document_id', $draft->id)->delete();
        }))->toThrow(PDOException::class, 'can see')
        ->and(lineRowOf($draft))->toBe($before);
})->with('row security roles')->with('context that hides the document')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses a line change through the model when the document is hidden', function (string $role, Closure $context): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $changed = DocumentLine::query()->where('document_id', $invoice->id)->sole();
    $deleted = DocumentLine::query()->where('document_id', $invoice->id)->sole();
    $changed->line_net_minor = 1;

    expect(fn () => inTenantContext($role, $context($team), static fn (): bool => $changed->save()))
        ->toThrow(DocumentIsImmutableException::class)
        ->and(fn () => inTenantContext($role, $context($team), static fn (): ?bool => $deleted->delete()))
        ->toThrow(DocumentIsImmutableException::class)
        ->and(lineCountOf($invoice))->toBe(1);
})->with('row security roles')->with('context that hides the document')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('leaves a finalized document it cannot see untouched', function (string $role, Closure $context): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $before = (array) DB::table('gobd_documents')->where('id', $invoice->id)->sole();

    $touched = inTenantContext($role, $context($team), static fn (): int => DB::table('gobd_documents')->where('id', $invoice->id)->update(['gross_total' => 1])
        + DB::table('gobd_documents')->where('id', $invoice->id)->update(['status' => DocumentStatus::Cancelled->value])
        + DB::table('gobd_documents')->where('id', $invoice->id)->delete());

    expect($touched)->toBe(0)
        ->and((array) DB::table('gobd_documents')->where('id', $invoice->id)->sole())->toBe($before);
})->with('row security roles')->with('context that hides the document')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to change or delete audit entries in any tenant context', function (string $role, Closure $context): void {
    $team = newTenant();
    $invoice = GobdInvoice::finalize(tenantDraft($team));
    $entries = DB::table('gobd_audit_log')->where('document_id', $invoice->id)->count();

    expect(fn () => inTenantContext($role, $context($team), static function () use ($invoice): void {
        DB::table('gobd_audit_log')->where('document_id', $invoice->id)->update(['event' => 'rewritten']);
    }))->toThrow(PDOException::class, 'append-only')
        ->and(fn () => inTenantContext($role, $context($team), static function () use ($invoice): void {
            DB::table('gobd_audit_log')->where('document_id', $invoice->id)->delete();
        }))->toThrow(PDOException::class, 'append-only')
        ->and(DB::table('gobd_audit_log')->where('document_id', $invoice->id)->count())->toBe($entries)
        ->and(GobdInvoice::verify($invoice))->toBeTrue();
})->with('row security roles')->with('any tenant context')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to rewind, rekey or delete a number counter in any tenant context', function (string $role, Closure $context): void {
    $team = newTenant();
    GobdInvoice::finalize(tenantDraft($team));
    $before = (array) DB::table('gobd_number_sequences')->where('team_id', $team)->sole();

    expect(fn () => inTenantContext($role, $context($team), static function () use ($team): void {
        DB::table('gobd_number_sequences')->where('team_id', $team)->update(['current_value' => 0]);
    }))->toThrow(PDOException::class, 'backwards')
        ->and(fn () => inTenantContext($role, $context($team), static function () use ($team): void {
            DB::table('gobd_number_sequences')->where('team_id', $team)->update(['year' => 2000]);
        }))->toThrow(PDOException::class, 'immutable')
        ->and(fn () => inTenantContext($role, $context($team), static function () use ($team): void {
            DB::table('gobd_number_sequences')->where('team_id', $team)->delete();
        }))->toThrow(PDOException::class, 'cannot be deleted')
        ->and((array) DB::table('gobd_number_sequences')->where('team_id', $team)->sole())->toBe($before);
})->with('row security roles')->with('any tenant context')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('drafts, edits and finalizes through the package inside the tenant context', function (string $role): void {
    $team = newTenant();

    $invoice = inTenantContext($role, $team, static function () use ($team): Document {
        $draft = tenantDraft($team);
        DB::table('gobd_document_lines')->where('document_id', $draft->id)->update(['description' => 'edited raw']);

        $line = DocumentLine::query()->where('document_id', $draft->id)->sole();
        $line->description = 'edited through the model';
        $line->save();

        $draft = GobdInvoice::updateDraft($draft, [], [...lineSet('20.00'), ...lineSet('5.00')]);

        return GobdInvoice::finalize($draft);
    });

    expect(Document::query()->find($invoice->id)?->finalized_at)->not->toBeNull()
        ->and(lineCountOf($invoice))->toBe(2)
        ->and(GobdInvoice::verify($invoice))->toBeTrue();
})->with('row security roles')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');
