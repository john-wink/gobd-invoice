<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Facades;

use Illuminate\Support\Facades\Facade;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\Document;

/**
 * @method static Document draft(DocumentType $type, array<string, mixed> $attributes = [], array<int, array<string, mixed>> $lines = [])
 * @method static Document finalize(Document $document)
 * @method static bool verify(Document $document)
 * @method static string eInvoiceXml(Document $document)
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
