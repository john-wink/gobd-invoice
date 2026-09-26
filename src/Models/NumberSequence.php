<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use JohnWink\GobdInvoice\Models\Concerns\HasConfiguredKey;
use Override;

/**
 * One monotonically increasing counter per (tenant, document_type, series,
 * year). Rows are locked with `lockForUpdate()` during number assignment to
 * make duplicate numbers impossible. See docs/research/08-package-architecture.md (B8).
 *
 * @property int|string $id
 * @property string $document_type
 * @property string $series
 * @property int $year
 * @property int $current_value
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Unguarded]
class NumberSequence extends Model
{
    use HasConfiguredKey;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(Config::string('gobd-invoice.table_names.sequences', 'gobd_number_sequences'));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'current_value' => 'integer',
        ];
    }
}
