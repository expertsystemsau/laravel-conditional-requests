<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Http\Middleware\Flags;

it('falls back to the default when no flags are given', function (): void {
    $flags = Flags::parse([]);

    expect($flags->strategy)->toBeNull()
        ->and($flags->required)->toBeFalse()
        ->and($flags->lock)->toBeFalse()
        ->and($flags->strategyOr('body'))->toBe('body');
});

it('reads a strategy name from a flag', function (): void {
    expect(Flags::parse(['model'])->strategyOr('body'))->toBe('model');
});

it('ignores empty flags', function (): void {
    // `conditional:` hands the middleware a single empty string.
    expect(Flags::parse([''])->strategyOr('body'))->toBe('body');
});

it('trims surrounding whitespace from a flag', function (): void {
    expect(Flags::parse([' model '])->strategyOr('body'))->toBe('model');
});

it('does not mistake a reserved word for a strategy name', function (): void {
    $flags = Flags::parse(['required']);

    expect($flags->strategy)->toBeNull()
        ->and($flags->required)->toBeTrue();
});

it('treats required as implying the model strategy', function (): void {
    expect(Flags::parse(['required'])->strategyOr('body'))->toBe('model');
});

it('treats lock as implying the model strategy', function (): void {
    expect(Flags::parse(['lock'])->strategyOr('body'))->toBe('model');
});

it('parses reserved words in either order', function (): void {
    $forward = Flags::parse(['required', 'lock']);
    $reverse = Flags::parse(['lock', 'required']);

    expect($forward->required)->toBeTrue()
        ->and($forward->lock)->toBeTrue()
        ->and($reverse->required)->toBeTrue()
        ->and($reverse->lock)->toBeTrue();
});

it('finds the strategy whatever position it holds', function (): void {
    expect(Flags::parse(['required', 'model'])->strategyOr('body'))->toBe('model')
        ->and(Flags::parse(['model', 'required'])->strategyOr('body'))->toBe('model');
});

it('lets an explicit strategy win over the reserved-word implication', function (): void {
    // A configuration error v0.3 catches at boot; until then the author's
    // explicit flag is honoured rather than silently overridden.
    expect(Flags::parse(['body', 'required'])->strategyOr('model'))->toBe('body');
});

it('takes the first of two strategy names', function (): void {
    expect(Flags::parse(['alpha', 'beta'])->strategyOr('body'))->toBe('alpha');
});
