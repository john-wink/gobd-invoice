<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\PaymentTerms;

it('reports a complete Skonto agreement', function (): void {
    $terms = new PaymentTerms(netDays: 30, skontoPercentage: '2.0', skontoDays: 10);

    expect($terms->hasSkonto())->toBeTrue()
        ->and($terms->netDays)->toBe(30)
        ->and($terms->skontoPercentage)->toBe('2.0')
        ->and($terms->skontoDays)->toBe(10);
});

it('reports no Skonto when the agreement is incomplete', function (): void {
    expect((new PaymentTerms(netDays: 14))->hasSkonto())->toBeFalse()
        ->and((new PaymentTerms(skontoPercentage: '2.0'))->hasSkonto())->toBeFalse()
        ->and((new PaymentTerms)->hasSkonto())->toBeFalse();
});

it('rejects invalid term values', function (callable $factory): void {
    expect($factory)->toThrow(InvalidArgumentException::class);
})->with([
    'negative net days' => [fn (): PaymentTerms => new PaymentTerms(netDays: -1)],
    'negative skonto days' => [fn (): PaymentTerms => new PaymentTerms(skontoDays: -5)],
    'non-numeric skonto percentage' => [fn (): PaymentTerms => new PaymentTerms(skontoPercentage: 'two')],
]);
