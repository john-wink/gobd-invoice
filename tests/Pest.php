<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
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
 * @return DOMXPath a namespace-registered XPath over the CII payload (also
 *                  asserts the XML is well-formed)
 */
function ciiXpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

    return $xpath;
}

function ciiValue(DOMXPath $xpath, string $query): ?string
{
    return $xpath->query($query)?->item(0)?->nodeValue;
}

/**
 * @return list<string>
 */
function ciiValues(DOMXPath $xpath, string $query): array
{
    $values = [];
    $nodes = $xpath->query($query);
    foreach ($nodes ?: [] as $node) {
        $values[] = (string) $node->nodeValue;
    }

    return $values;
}

/**
 * @return DOMXPath a namespace-registered XPath over the UBL payload (also
 *                  asserts the XML is well-formed)
 */
function ublXpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xpath->registerNamespace('creditnote', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

    return $xpath;
}

function ublValue(DOMXPath $xpath, string $query): ?string
{
    return $xpath->query($query)?->item(0)?->nodeValue;
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

dataset('row security roles', [
    'table owner under FORCE ROW LEVEL SECURITY' => 'owner',
    'application role' => 'application',
]);

/**
 * Run database work as a role that sees only the documents of the tenant in
 * the session setting `gobd.tenant` — the row level security a host such as
 * craftplan-next puts on the package tables. The role has no BYPASSRLS; as
 * 'owner' it also owns the documents table, which FORCE ROW LEVEL SECURITY
 * keeps under the policy. PostgreSQL only.
 *
 * @template TResult
 *
 * @param  Closure(): TResult  $work
 * @return TResult
 */
function underTenantRowSecurity(string $role, Closure $work): mixed
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
    DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO gobd_tenant_app');
    DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO gobd_tenant_app');
    DB::statement('ALTER TABLE gobd_documents ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE gobd_documents FORCE ROW LEVEL SECURITY');
    DB::statement('DROP POLICY IF EXISTS gobd_documents_of_tenant ON gobd_documents');
    DB::statement(<<<'SQL'
        CREATE POLICY gobd_documents_of_tenant ON gobd_documents
            USING (team_id::text = current_setting('gobd.tenant', true))
            WITH CHECK (team_id::text = current_setting('gobd.tenant', true))
        SQL);

    if ($role === 'owner') {
        DB::statement('ALTER TABLE gobd_documents OWNER TO gobd_tenant_app');
    }

    DB::statement('SET ROLE gobd_tenant_app');

    try {
        return $work();
    } finally {
        DB::statement('RESET ROLE');
        enterTenantContext(null);
    }
}

/**
 * Set (or clear, with null) the tenant the session sees under
 * {@see underTenantRowSecurity()}, for the session rather than the
 * transaction — the way a host's runFor() sets and resets its context.
 */
function enterTenantContext(?string $tenant): void
{
    DB::select("SELECT set_config('gobd.tenant', ?, false)", [$tenant ?? '']);
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

const SIGNAL_TIMEOUT_SECONDS = 10;

/**
 * Wait in a forked process until another process touched the signal file.
 */
function awaitSignal(string $signal, string $failure): void
{
    $deadline = microtime(true) + SIGNAL_TIMEOUT_SECONDS;

    while (! is_file($signal)) {
        throw_if(microtime(true) > $deadline, RuntimeException::class, $failure);

        clearstatcache(true, $signal);
        Sleep::usleep(1_000);
    }
}

/**
 * Update, insert or delete a line of the document directly, the way a host
 * connection past the models would. PostgreSQL only.
 */
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
