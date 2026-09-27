<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JohnWink\GobdInvoice\Audit\AppendOnlyAuditLogger;
use JohnWink\GobdInvoice\Audit\ContentHasher;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TenantTestCase;
use JohnWink\GobdInvoice\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(TenantTestCase::class)->in('Tenancy');

/**
 * A minimal single-line payload for drafting an invoice in tests.
 *
 * @return array<int, array<string, string>>
 */
function lineSet(string $price = '10.00'): array
{
    return [['description' => 'x', 'quantity' => '1', 'unit_price' => $price]];
}

/**
 * Draft a document with complete §14 seller/buyer parties (as an e-invoice
 * requires). Document-level `$attributes` are merged over the defaults, so a
 * caller can override e.g. `buyer`, `currency` or `meta`.
 *
 * @param  array<int, array<string, mixed>>  $lines
 * @param  array<string, mixed>  $attributes
 */
function draftWithParties(DocumentType $documentType, array $lines, array $attributes = []): Document
{
    return GobdInvoice::draft($documentType, array_merge([
        'seller' => ['name' => 'Muster GmbH', 'address_line' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE', 'vat_id' => 'DE123456789'],
        'buyer' => ['name' => 'Kunde AG', 'address_line' => 'Nebenweg 2', 'postal_code' => '80331', 'city' => 'München', 'country' => 'DE'],
        'payment_terms' => ['net_days' => 30, 'note' => 'Zahlbar innerhalb von 30 Tagen.'],
    ], $attributes), $lines);
}

/**
 * Rebind the AuditLogger to one that throws on the given event so the
 * finalize/cancel failure paths can be exercised. Forces the manager singleton
 * and the facade cache to rebuild with the throwing logger.
 */
function failAuditOn(string $event): void
{
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);

    app()->bind(AuditLogger::class, fn (): AuditLogger => new class($event) implements AuditLogger
    {
        public function __construct(private string $failEvent) {}

        /** @param array<string, mixed> $context */
        public function append(InvoiceDocument $invoiceDocument, string $event, array $context = []): Model
        {
            if ($event === $this->failEvent) {
                throw new RuntimeException("audit boom on [{$event}]");
            }

            return (new AppendOnlyAuditLogger(app(ContentHasher::class), app(JohnWink\GobdInvoice\Contracts\ActorResolver::class)))->append($invoiceDocument, $event, $context);
        }

        public function verify(InvoiceDocument $invoiceDocument): bool
        {
            return (new AppendOnlyAuditLogger(app(ContentHasher::class), app(JohnWink\GobdInvoice\Contracts\ActorResolver::class)))->verify($invoiceDocument);
        }
    });
}

function restoreRealAuditLogger(): void
{
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);
    app()->bind(AuditLogger::class, AppendOnlyAuditLogger::class);
}

/**
 * A fresh tenant key, shaped like the team ids of a UUID host.
 */
function newTenant(): string
{
    return (string) Str::uuid7();
}

/**
 * Draft a document for the given tenant in the multi-tenant configuration.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>|null  $lines
 */
function tenantDraft(string $tenant, DocumentType $documentType = DocumentType::Rechnung, array $attributes = [], ?array $lines = null): Document
{
    return GobdInvoice::draft($documentType, [TenantTestCase::TENANT_COLUMN => $tenant, ...$attributes], $lines ?? lineSet());
}

dataset('database roles', [
    'table owner' => 'owner',
    'application role' => 'application',
]);

/**
 * Run database work as the owner of the package tables (the connection user)
 * or as a plain application role that only holds DML privileges — the role a
 * production host usually connects with. PostgreSQL only.
 *
 * @template TResult
 *
 * @param  Closure(): TResult  $work
 * @return TResult
 */
function asDatabaseRole(string $role, Closure $work): mixed
{
    if ($role === 'owner') {
        return $work();
    }

    DB::statement(<<<'SQL'
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'gobd_app') THEN
                CREATE ROLE gobd_app NOLOGIN NOSUPERUSER NOBYPASSRLS;
            END IF;
        END
        $$
        SQL);
    DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO gobd_app');
    DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO gobd_app');
    DB::statement('SET ROLE gobd_app');

    try {
        return $work();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/**
 * The chain ends of a document's audit trail: entries no other entry points
 * to. An intact trail has exactly one.
 *
 * @return list<string|null>
 */
function auditChainEnds(int|string $documentId): array
{
    $entries = AuditLogEntry::query()->where('document_id', $documentId)->get();
    $referenced = $entries->pluck('previous_hash')->filter()->all();

    return $entries->reject(static fn (AuditLogEntry $entry): bool => in_array($entry->content_hash, $referenced, true))
        ->pluck('content_hash')
        ->values()
        ->all();
}

/**
 * Simulate an operator with direct database access who deliberately bypasses
 * the PostgreSQL guard triggers (superuser: session_replication_role). Used to
 * prove that verify() still detects tampering the guards could not prevent.
 *
 * @param  Closure(): void  $tampering
 */
function tamperBypassingDatabaseGuards(Closure $tampering): void
{
    if (! TestCase::usesPostgres()) {
        $tampering();

        return;
    }

    DB::statement('SET session_replication_role = replica');

    try {
        $tampering();
    } finally {
        DB::statement('SET session_replication_role = DEFAULT');
    }
}
