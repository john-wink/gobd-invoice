<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Models\Document;

/**
 * @extends Factory<Document>
 */
final class DocumentFactory extends Factory
{
    /** @var class-string<Document> */
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'type' => DocumentType::Rechnung,
            'status' => DocumentStatus::Draft,
            'currency' => 'EUR',
            'series' => DocumentType::Rechnung->defaultSeries(),
            'is_financial_sector' => false,
            'retention_class' => 'voucher',
        ];
    }

    public function finalized(): self
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Finalized,
            'finalized_at' => now(),
        ]);
    }
}
