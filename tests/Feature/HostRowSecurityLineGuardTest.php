<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * The line guard of a single-tenant installation under a host's own row level
 * security: the host adds its tenant column to the documents table and a
 * policy on it, while the package itself keeps no tenant column. Without the
 * package's tenant check, only the line guard stands between a session that
 * cannot see a festgeschriebenes document and a change to its lines.
 */

const HOST_TENANT = 'host-tenant-a';

dataset('host context that hides the document', [
    'context cleared' => static fn (): ?string => null,
    'context of another host tenant' => static fn (): string => 'host-tenant-b',
]);

/**
 * Run database work as a role without BYPASSRLS that sees only the documents
 * of the host tenant in the session setting `gobd.tenant`. As 'owner' it also
 * owns the documents table, which FORCE ROW LEVEL SECURITY keeps under the
 * policy. PostgreSQL only.
 *
 * @template TResult
 *
 * @param  Closure(): TResult  $work
 * @return TResult
 */
function underHostRowSecurity(string $role, ?string $context, Closure $work): mixed
{
    DB::statement(<<<'SQL'
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'gobd_tenant_app') THEN
                CREATE ROLE gobd_tenant_app NOLOGIN NOSUPERUSER NOBYPASSRLS;
            END IF;
        END
        $$
        SQL);
    DB::statement("ALTER TABLE gobd_documents ADD COLUMN IF NOT EXISTS host_tenant text NOT NULL DEFAULT '".HOST_TENANT."'");
    DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO gobd_tenant_app');
    DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO gobd_tenant_app');
    DB::statement('ALTER TABLE gobd_documents ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE gobd_documents FORCE ROW LEVEL SECURITY');
    DB::statement('DROP POLICY IF EXISTS gobd_documents_of_host_tenant ON gobd_documents');
    DB::statement(<<<'SQL'
        CREATE POLICY gobd_documents_of_host_tenant ON gobd_documents
            USING (host_tenant = current_setting('gobd.tenant', true))
            WITH CHECK (host_tenant = current_setting('gobd.tenant', true))
        SQL);

    if ($role === 'owner') {
        DB::statement('ALTER TABLE gobd_documents OWNER TO gobd_tenant_app');
    }

    DB::statement('SET ROLE gobd_tenant_app');
    enterTenantContext($context);

    try {
        return $work();
    } finally {
        DB::statement('RESET ROLE');
        enterTenantContext(null);
    }
}

function finalizedSingleTenantInvoice(): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet()));
}

/**
 * @return array<string, mixed>
 */
function singleTenantLineOf(Document $document): array
{
    return (array) DB::table('gobd_document_lines')->where('document_id', $document->id)->sole();
}

it('refuses to change a line of a finalized document the session cannot see', function (string $role, Closure $context): void {
    $invoice = finalizedSingleTenantInvoice();
    $before = singleTenantLineOf($invoice);

    expect(fn () => underHostRowSecurity($role, $context(), static function () use ($invoice): void {
        DB::table('gobd_document_lines')->where('document_id', $invoice->id)->update(['line_net_minor' => 1]);
    }))->toThrow(PDOException::class, 'can see')
        ->and(singleTenantLineOf($invoice))->toBe($before);
})->with('row security roles')->with('host context that hides the document')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to add a line to a finalized document the session cannot see', function (string $role, Closure $context): void {
    $invoice = finalizedSingleTenantInvoice();
    $added = singleTenantLineOf($invoice);
    unset($added['id']);
    $added['position'] = 2;

    expect(fn () => underHostRowSecurity($role, $context(), static function () use ($added): void {
        DB::table('gobd_document_lines')->insert($added);
    }))->toThrow(PDOException::class, 'can see')
        ->and(DB::table('gobd_document_lines')->where('document_id', $invoice->id)->count())->toBe(1);
})->with('row security roles')->with('host context that hides the document')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('refuses to delete a line of a finalized document the session cannot see', function (string $role, Closure $context): void {
    $invoice = finalizedSingleTenantInvoice();

    expect(fn () => underHostRowSecurity($role, $context(), static function () use ($invoice): void {
        DB::table('gobd_document_lines')->where('document_id', $invoice->id)->delete();
    }))->toThrow(PDOException::class, 'can see')
        ->and(DB::table('gobd_document_lines')->where('document_id', $invoice->id)->count())->toBe(1);
})->with('row security roles')->with('host context that hides the document')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');

it('edits the lines of a draft and finalizes it inside the host context', function (string $role): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], lineSet());

    $invoice = underHostRowSecurity($role, HOST_TENANT, static function () use ($draft): Document {
        DB::table('gobd_document_lines')->where('document_id', $draft->id)->update(['description' => 'edited raw']);

        return GobdInvoice::finalize(GobdInvoice::updateDraft($draft, [], [...lineSet('20.00'), ...lineSet('5.00')]));
    });

    expect(Document::query()->find($invoice->id)?->finalized_at)->not->toBeNull()
        ->and(DB::table('gobd_document_lines')->where('document_id', $invoice->id)->count())->toBe(2)
        ->and(GobdInvoice::verify($invoice))->toBeTrue();
})->with('row security roles')
    ->skip(! TestCase::usesPostgres(), 'Row level security exists on PostgreSQL only.');
