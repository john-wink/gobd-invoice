<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use JohnWink\GobdInvoice\Audit\AppendOnlyAuditLogger;
use JohnWink\GobdInvoice\Audit\ContentHasher;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\GobdInvoiceManager;
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
