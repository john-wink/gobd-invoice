<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\Party;

it('recognises a complete name and address', function (): void {
    $party = new Party('Muster GmbH', 'Hauptstr. 1', '10115', 'Berlin');

    expect($party->hasCompleteAddress())->toBeTrue();
});

it('treats a missing or blank address part as incomplete', function (Party $party): void {
    expect($party->hasCompleteAddress())->toBeFalse();
})->with([
    'no name' => [new Party('', 'Hauptstr. 1', '10115', 'Berlin')],
    'no street' => [new Party('Muster GmbH', null, '10115', 'Berlin')],
    'no postal code' => [new Party('Muster GmbH', 'Hauptstr. 1', null, 'Berlin')],
    'blank city' => [new Party('Muster GmbH', 'Hauptstr. 1', '10115', '   ')],
]);

it('recognises a tax number or a VAT id as a tax identifier', function (): void {
    expect((new Party('X', taxNumber: '29/123/45678'))->hasTaxId())->toBeTrue()
        ->and((new Party('X', vatId: 'DE123456789'))->hasTaxId())->toBeTrue()
        ->and((new Party('X'))->hasTaxId())->toBeFalse();
});

it('round-trips through array form', function (): void {
    $party = new Party('Muster GmbH', 'Hauptstr. 1', '10115', 'Berlin', 'DE', '29/123/45678', 'DE123456789', 'rechnung@muster.de');

    $rebuilt = Party::fromArray($party->toArray());

    expect($rebuilt->name)->toBe('Muster GmbH')
        ->and($rebuilt->postalCode)->toBe('10115')
        ->and($rebuilt->country)->toBe('DE')
        ->and($rebuilt->vatId)->toBe('DE123456789')
        ->and($rebuilt->email)->toBe('rechnung@muster.de')  // BT-43/BT-41 contact email
        ->and($rebuilt->hasCompleteAddress())->toBeTrue();
});
