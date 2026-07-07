<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;

/**
 * Contract implemented by the persisted document model so host apps can swap
 * the Eloquent model (the spatie/laravel-permission pattern). The package binds
 * this contract to `config('gobd-invoice.models.document')`.
 */
interface InvoiceDocument
{
    public function documentType(): DocumentType;

    public function documentStatus(): DocumentStatus;

    /**
     * Whether the document has been finalized (festgeschrieben) and its
     * tax-relevant content is therefore immutable (Unveränderbarkeit, GoBD).
     */
    public function isImmutable(): bool;
}
