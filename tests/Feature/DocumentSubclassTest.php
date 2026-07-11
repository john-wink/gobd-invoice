<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Models\Document;

/**
 * The package advertises swappable models, so a host may subclass Document (e.g.
 * to add multi-tenancy). Eloquent infers a HasMany foreign key from the parent
 * CLASS name, which would break a subclass (`<subclass>_id` instead of
 * `document_id`); the relations must therefore pin the key explicitly.
 */
it('keeps document_id foreign keys when Document is subclassed', function (): void {
    $subclass = new class extends Document {};

    expect($subclass->lines()->getForeignKeyName())->toBe('document_id')
        ->and($subclass->auditEntries()->getForeignKeyName())->toBe('document_id');
});

it('links source() to the same (sub)class so a scoped subclass stays scoped', function (): void {
    $subclass = new class extends Document {};

    expect($subclass->source()->getRelated())->toBeInstanceOf($subclass::class);
});
