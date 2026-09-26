<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Models\Concerns;

use JohnWink\GobdInvoice\Enums\KeyType;
use Override;

/**
 * Keys a package model by auto-increment or by UUIDv7, following
 * {@see KeyType::configured()}. Mirrors Laravel's HasUuids, but decided at
 * runtime so one model class serves both schemas.
 */
trait HasConfiguredKey
{
    public function initializeHasConfiguredKey(): void
    {
        $this->usesUniqueIds = KeyType::configured() === KeyType::Uuid;
    }

    #[Override]
    public function newUniqueId(): ?string
    {
        return KeyType::configured()->newKey();
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function uniqueIds(): array
    {
        return $this->usesUniqueIds ? [$this->getKeyName()] : [];
    }

    #[Override]
    public function getKeyType(): string
    {
        return $this->usesUniqueIds ? 'string' : parent::getKeyType();
    }

    #[Override]
    public function getIncrementing(): bool
    {
        return $this->usesUniqueIds ? false : parent::getIncrementing();
    }
}
