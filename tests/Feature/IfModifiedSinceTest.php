<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Reading;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * A read route with a counter on the controller. The counter is the point:
 * a 304 says nothing about whether the controller ran, and the pre-controller
 * short-circuit is the difference between saving bandwidth and saving compute.
 */
function datedReadingRoute(?int &$runs): void
{
    $runs = 0;

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/readings/{reading}', function (Reading $reading) use (&$runs): array {
            $runs++;

            return ['label' => $reading->label];
        });
}

function readingWrittenAt(string $instant): void
{
    test()->travelTo(Carbon::parse($instant, 'UTC'));

    Reading::create(['label' => 'Hello']);
}

it('answers a matching If-Modified-Since with 304', function (): void {
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $lastModified = (string) $this->get('/readings/1')->headers->get('Last-Modified');
    $runs = 0;

    $this->get('/readings/1', ['If-Modified-Since' => $lastModified])->assertStatus(304);

    // Answered after the controller, unlike a matching If-None-Match. A date
    // needs no prior access, so a date-only client is refused the pre-controller
    // short-circuit for the same reason `If-None-Match: *` is — see
    // Conditional::dateOnly(). The 304 above is unchanged; only the compute
    // saving is surrendered.
    expect($runs)->toBe(1);
});

it('answers a stale If-Modified-Since with the representation', function (): void {
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $this->get('/readings/1', ['If-Modified-Since' => 'Wed, 26 Aug 2026 11:59:59 GMT'])
        ->assertOk()
        ->assertJson(['label' => 'Hello']);

    expect($runs)->toBe(1);
});

it('answers a HEAD with the same 304', function (): void {
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $this->head('/readings/1', ['If-Modified-Since' => 'Wed, 26 Aug 2026 12:00:00 GMT'])->assertStatus(304);
});

it('serves the new representation once the record changes in a later second', function (): void {
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $lastModified = (string) $this->get('/readings/1')->headers->get('Last-Modified');

    test()->travelTo(Carbon::parse('2026-08-26 12:00:07', 'UTC'));
    Reading::query()->findOrFail(1)->update(['label' => 'Changed']);
    test()->travelTo(Carbon::parse('2026-08-26 12:00:09', 'UTC'));

    $this->get('/readings/1', ['If-Modified-Since' => $lastModified])
        ->assertOk()
        ->assertJson(['label' => 'Changed']);
});

it('never advertises a date that a second change in the same second could invalidate', function (): void {
    // The bug this phase exists to prevent, run end to end.
    //
    // The record changes at 12:00:00.700 and again at 12:00:00.950. Both
    // truncate to 12:00:00. If the first response had advertised that date, the
    // client would echo it back after the second change and be told 304 while
    // holding a 250ms-stale representation. It cannot, because the second was
    // still open — and the tag it does get changes with the second write.
    readingWrittenAt('2026-08-26 12:00:00.700000');

    datedReadingRoute($runs);

    test()->travelTo(Carbon::parse('2026-08-26 12:00:00.900000', 'UTC'));
    $early = $this->get('/readings/1');
    $early->assertHeaderMissing('Last-Modified');
    $earlyTag = (string) $early->headers->get('ETag');

    test()->travelTo(Carbon::parse('2026-08-26 12:00:00.950000', 'UTC'));
    Reading::query()->findOrFail(1)->update(['label' => 'Changed']);

    test()->travelTo(Carbon::parse('2026-08-26 12:00:02', 'UTC'));
    $late = $this->get('/readings/1');

    // Now the second is closed, so the date is publishable — and it names the
    // last change in it, which is the version this client is holding.
    $late->assertHeader('Last-Modified', 'Wed, 26 Aug 2026 12:00:00 GMT');
    expect((string) $late->headers->get('ETag'))->not->toBe($earlyTag);

    $this->get('/readings/1', ['If-Modified-Since' => 'Wed, 26 Aug 2026 12:00:00 GMT'])->assertStatus(304);
    $this->get('/readings/1', ['If-None-Match' => $earlyTag])->assertOk();
});

it('publishes no date at the last microsecond of the second it changed in', function (): void {
    readingWrittenAt('2026-08-26 12:00:00.000000');

    datedReadingRoute($runs);

    test()->travelTo(Carbon::parse('2026-08-26 12:00:00.999999', 'UTC'));

    $this->get('/readings/1')->assertOk()->assertHeaderMissing('Last-Modified');
});

it('publishes the date the moment that second closes', function (): void {
    readingWrittenAt('2026-08-26 12:00:00.000000');

    datedReadingRoute($runs);

    test()->travelTo(Carbon::parse('2026-08-26 12:00:01.000000', 'UTC'));

    $this->get('/readings/1')->assertHeader('Last-Modified', 'Wed, 26 Aug 2026 12:00:00 GMT');
});

it('prefers If-None-Match when both conditional headers are sent', function (): void {
    // RFC 9110 §13.2.2 step 3 before step 4: a stale tag means "not the version
    // you have", whatever the date says.
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $this->get('/readings/1', [
        'If-None-Match' => '"stale-tag"',
        'If-Modified-Since' => 'Wed, 26 Aug 2026 12:00:00 GMT',
    ])->assertOk();
});

it('honours a matching If-None-Match over a stale date', function (): void {
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $etag = (string) $this->get('/readings/1')->headers->get('ETag');
    $runs = 0;

    $this->get('/readings/1', [
        'If-None-Match' => $etag,
        'If-Modified-Since' => 'Wed, 26 Aug 2026 11:00:00 GMT',
    ])->assertStatus(304);

    // The date arrived alongside a tag the client demonstrably holds, so the
    // short-circuit stands — the same line dateOnly() draws.
    expect($runs)->toBe(0);
});

it('ignores a malformed If-Modified-Since rather than failing', function (): void {
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $this->get('/readings/1', ['If-Modified-Since' => 'not a date'])->assertOk();
});

it('strips the date from a 304 and keeps the tag', function (): void {
    // Response::setNotModified() removes Last-Modified and keeps the ETag.
    // Nothing compensates: RFC 9110 §15.4.5 requires the tag and permits the
    // date only when there is no tag, and RFC 9111 §4.3.4 has the client keep
    // the date it already stored from the 200.
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    datedReadingRoute($runs);

    $this->get('/readings/1', ['If-Modified-Since' => 'Wed, 26 Aug 2026 12:00:00 GMT'])
        ->assertStatus(304)
        ->assertHeader('ETag')
        ->assertHeaderMissing('Last-Modified');
});

it('cannot match a date on a body hash route, because it published none', function (): void {
    // The body-hash strategy has no date, so this route can only 304 on a tag —
    // proving the date path and the short-circuit are independent of each other.
    readingWrittenAt('2026-08-26 12:00:00');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    Route::middleware([SubstituteBindings::class, 'conditional'])
        ->get('/readings/{reading}', fn (Reading $reading): array => ['label' => $reading->label]);

    $this->get('/readings/1', ['If-Modified-Since' => 'Wed, 26 Aug 2026 12:00:00 GMT'])
        ->assertOk()
        ->assertHeaderMissing('Last-Modified');
});

it('publishes no date for a record dated in the future', function (): void {
    readingWrittenAt('2026-08-26 12:00:30');
    test()->travelTo(Carbon::parse('2026-08-26 12:00:00', 'UTC'));

    datedReadingRoute($runs);

    $this->get('/readings/1')->assertOk()->assertHeaderMissing('Last-Modified');
});
