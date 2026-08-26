<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Exceptions\PreconditionFailedException;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Reading;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * A record written at a known instant, and the guarded routes that address it.
 * The counter proves the refusal happens before the controller — a guard that
 * refuses afterwards has already lost the update it was protecting.
 */
function datedWriteRoutes(?int &$runs, string $middleware = 'conditional:required'): void
{
    $runs = 0;

    $action = function (Reading $reading) use (&$runs): array {
        $runs++;

        return ['label' => $reading->label];
    };

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/readings/{reading}', $action);

    foreach (['put', 'patch', 'delete', 'post'] as $method) {
        Route::middleware([SubstituteBindings::class, $middleware])
            ->{$method}('/readings/{reading}', $action);
    }
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-08-26 12:00:00', 'UTC'));

    Reading::create(['label' => 'Hello']);

    $this->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));
});

it('lets a write through when If-Unmodified-Since is later than the change', function (): void {
    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:03 GMT'])->assertOk();

    expect($runs)->toBe(1);
});

it('lets a write through when the client echoes the date it was given', function (): void {
    // The whole point of the header, and the case a raw sub-second comparison
    // would refuse forever.
    datedWriteRoutes($runs);

    $lastModified = (string) $this->get('/readings/1')->headers->get('Last-Modified');
    $runs = 0;

    expect($lastModified)->toBe('Wed, 26 Aug 2026 12:00:00 GMT');

    $this->put('/readings/1', [], ['If-Unmodified-Since' => $lastModified])->assertOk();

    expect($runs)->toBe(1);
});

it('lets a write through when the record changed mid second and the client echoes the floor', function (): void {
    $this->travelTo(Carbon::parse('2026-08-26 12:00:10.700000', 'UTC'));
    Reading::query()->findOrFail(1)->update(['label' => 'Changed']);
    $this->travelTo(Carbon::parse('2026-08-26 12:00:12', 'UTC'));

    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:10 GMT'])->assertOk();
});

it('refuses a write whose If-Unmodified-Since predates the change', function (): void {
    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 11:59:59 GMT'])->assertStatus(412);

    expect($runs)->toBe(0);
});

it('answers a refused date precondition with the packages message', function (): void {
    datedWriteRoutes($runs);

    $this->putJson('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 11:59:59 GMT'])
        ->assertStatus(412)
        ->assertJsonPath('message', trans(PreconditionFailedException::MESSAGE_KEY));
});

it('guards every unsafe method', function (): void {
    datedWriteRoutes($runs);

    $stale = ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 11:59:59 GMT'];

    $this->patch('/readings/1', [], $stale)->assertStatus(412);
    $this->delete('/readings/1', [], $stale)->assertStatus(412);
    $this->post('/readings/1', [], $stale)->assertStatus(412);
});

it('accepts If-Unmodified-Since as the precondition a required route demands', function (): void {
    // The gap v0.3 shipped with: this request was answered 428 until now.
    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:03 GMT'])->assertOk();
});

it('refuses a date precondition when the resource publishes no date', function (): void {
    // Two ways to get here: a model that keeps no timestamps, and this — the
    // deployment opting out of the family. Both fail closed rather than
    // leaving a client that asked for a guard silently unguarded.
    config()->set('laravel-conditional-requests.last_modified', false);

    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:03 GMT'])->assertStatus(412);

    expect($runs)->toBe(0);
});

it('refuses a date precondition while the record is inside the second it changed in', function (): void {
    $this->travelTo(Carbon::parse('2026-08-26 12:00:10.700000', 'UTC'));
    Reading::query()->findOrFail(1)->update(['label' => 'Changed']);
    $this->travelTo(Carbon::parse('2026-08-26 12:00:10.900000', 'UTC'));

    datedWriteRoutes($runs);

    // No date is publishable yet, so no date precondition can be evaluated —
    // and a second change could still land in this same second, which is
    // exactly the ambiguity that makes proceeding unsafe.
    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:10 GMT'])->assertStatus(412);
});

it('ignores a malformed If-Unmodified-Since', function (): void {
    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'not a date'])->assertStatus(428);
});

it('lets a malformed date through on a route that does not require one', function (): void {
    datedWriteRoutes($runs, 'conditional:model');

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'not a date'])->assertOk();
});

it('ignores If-Modified-Since on a write', function (): void {
    // §13.1.3 restricts If-Modified-Since to GET and HEAD, so it is not a
    // precondition here and does not satisfy a required route.
    datedWriteRoutes($runs);

    $this->put('/readings/1', [], ['If-Modified-Since' => 'Wed, 26 Aug 2026 12:00:03 GMT'])->assertStatus(428);
});

it('prefers If-Match over If-Unmodified-Since', function (): void {
    datedWriteRoutes($runs);

    $etag = (string) $this->get('/readings/1')->headers->get('ETag');

    // The date would refuse this write; the tag is current, so it proceeds.
    $this->put('/readings/1', [], [
        'If-Match' => $etag,
        'If-Unmodified-Since' => 'Wed, 26 Aug 2026 11:00:00 GMT',
    ])->assertOk();

    // And the other way round: the tag is stale, so the satisfied date is
    // never consulted.
    $this->put('/readings/1', [], [
        'If-Match' => '"stale-tag"',
        'If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:03 GMT',
    ])->assertStatus(412);
});

it('refuses a date precondition a strategy cannot evaluate rather than discarding it', function (): void {
    // The v0.3 rule, extended to the header v0.4 added. `body` cannot produce a
    // validator before the controller runs, so it cannot evaluate this
    // precondition at all — and discarding it silently is the exact failure
    // that rule exists to prevent: the route looks guarded, answers 200, and
    // the client believes its check was honoured.
    $runs = 0;

    Route::middleware([SubstituteBindings::class, 'conditional'])
        ->patch('/readings/{reading}', function (Reading $reading) use (&$runs): array {
            $runs++;
            $reading->update(['label' => 'Clobbered']);

            return ['label' => $reading->label];
        });

    $this->patch('/readings/1', [], ['If-Unmodified-Since' => 'Wed, 26 Aug 2026 12:00:03 GMT'])->assertStatus(412);

    expect($runs)->toBe(0)
        ->and(Reading::query()->findOrFail(1)->label)->toBe('Hello');
});

it('lets an unevaluable route through when the date is not a date at all', function (): void {
    // §13.1.4 ignores a field value that is not a valid HTTP-date, so there is
    // no precondition to refuse and the guard stays opt-in — read exactly as
    // evaluate() reads it, so the two cannot disagree.
    Route::middleware([SubstituteBindings::class, 'conditional:body'])
        ->put('/readings/{reading}', fn (Reading $reading): array => ['label' => $reading->label]);

    $this->put('/readings/1', [], ['If-Unmodified-Since' => 'not a date'])->assertOk();
    $this->put('/readings/1', [], ['If-Unmodified-Since' => '   '])->assertOk();
    $this->put('/readings/1')->assertOk();
});
