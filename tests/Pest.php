<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use JohnWink\GobdInvoice\Audit\AppendOnlyAuditLogger;
use JohnWink\GobdInvoice\Audit\ContentHasher;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

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

            return (new AppendOnlyAuditLogger(app(ContentHasher::class)))->append($invoiceDocument, $event, $context);
        }

        public function verify(InvoiceDocument $invoiceDocument): bool
        {
            return (new AppendOnlyAuditLogger(app(ContentHasher::class)))->verify($invoiceDocument);
        }
    });
}

function restoreRealAuditLogger(): void
{
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);
    app()->bind(AuditLogger::class, AppendOnlyAuditLogger::class);
}
