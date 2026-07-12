<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Facades;

use Illuminate\Support\Facades\Facade;
use JohnWink\En16931\ValidationResult;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Export\Datev\DatevExportOptions;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\ValueObjects\DunningAssessment;
use JohnWink\GobdInvoice\ValueObjects\DunningOptions;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\ParsedEInvoice;

/**
 * @method static Document draft(DocumentType $type, array<string, mixed> $attributes = [], array<int, array<string, mixed>> $lines = [])
 * @method static Document updateDraft(Document $document, array<string, mixed> $attributes = [], array<int, array<string, mixed>> $lines = [])
 * @method static Document finalize(Document $document)
 * @method static bool verify(Document $document)
 * @method static string eInvoiceXml(Document $document)
 * @method static string eInvoicePdf(Document $document, string $basePdf)
 * @method static ParsedEInvoice parseEInvoice(string $xml)
 * @method static ValidationResult validateEInvoice(string $xml)
 * @method static array<string, string> exportGdpdu(iterable<Document> $documents)
 * @method static string exportDatev(iterable<Document> $documents, DatevExportOptions $options)
 * @method static DunningAssessment assessDunning(Money $principal, DunningOptions $options)
 * @method static Document dun(Document $document, DunningOptions $options)
 * @method static Document cancel(Document $document, string $reason)
 * @method static Document convert(Document $document, DocumentType $target, array<string, mixed> $overrides = [])
 *
 * @see GobdInvoiceManager
 */
final class GobdInvoice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GobdInvoiceManager::class;
    }
}
