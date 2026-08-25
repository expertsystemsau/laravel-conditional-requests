<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function (): void {
    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);
});

it('gives a HEAD request the same ETag as the equivalent GET', function (): void {
    expect($this->head('/articles')->headers->get('ETag'))
        ->toBe($this->get('/articles')->headers->get('ETag'));
});

it('returns 304 for a HEAD request holding the current version', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $this->head('/articles', ['If-None-Match' => $etag])->assertStatus(304);
});

it('keeps the ETag on a GET 304 so the client can refresh its cache entry', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $response = $this->get('/articles', ['If-None-Match' => $etag]);

    expect($response->status())->toBe(304)
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('keeps the ETag on a HEAD 304 so the client can refresh its cache entry', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $response = $this->head('/articles', ['If-None-Match' => $etag]);

    expect($response->status())->toBe(304)
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('sends no body on a HEAD request', function (): void {
    expect($this->head('/articles')->getContent())->toBe('');
});

it('sends no body on a HEAD request the middleware skips', function (): void {
    // Under route placement Router::runRoute()'s own prepareResponse would empty
    // this anyway, so this asserts the outcome rather than guarding the fix. The
    // global-placement case below is the one that regresses without it.
    config()->set('laravel-conditional-requests.exclude', ['internal/*']);

    Route::middleware('conditional')->get('/internal/metrics', fn () => response('metrics payload'));

    $response = $this->head('/internal/metrics');

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBeNull();
});

it('sends no body on a HEAD request to an ineligible response under global placement', function (): void {
    // Out here the middleware leaves the method alone, so the router's own
    // prepareResponse() sees the HEAD and empties the body; the middleware's
    // nulling arrives first and agrees with it. What this pins is the promise —
    // a HEAD to an ineligible response sends no body and keeps the tag the
    // application set — rather than which layer delivered it.
    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::get('/pre-tagged', fn () => response('ineligible payload')->setEtag('application-owned'));

    $response = $this->head('/pre-tagged');

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe('"application-owned"');
});

it('sends no body on a HEAD request to a file download under global placement', function (): void {
    // BinaryFileResponse::setContent(null) is a no-op; its body suppression
    // comes from prepare() zeroing maxlen for a HEAD request. Under route
    // placement the controller saw a GET and the middleware has to re-prepare
    // against the restored method to get that; out here nothing was mutated and
    // the router prepared against the HEAD itself. Either way, a HEAD to a
    // download must stream nothing.
    app(Kernel::class)->pushMiddleware(Conditional::class);

    $path = tempnam(sys_get_temp_dir(), 'crt');
    file_put_contents($path, str_repeat('a', 64));

    Route::get('/download', fn (): BinaryFileResponse => new BinaryFileResponse($path));

    $response = $this->head('/download');

    // Buffer the send by hand: TestResponse::streamedContent() only learned
    // about BinaryFileResponse after Laravel 12.0, and this has to hold on the
    // whole supported matrix. The file has to outlive the send.
    ob_start();
    $response->baseResponse->sendContent();
    $body = (string) ob_get_clean();

    unlink($path);

    expect($body)->toBe('');
});

it('leaves the request method alone when nothing has been routed yet', function (): void {
    // Under kernel-global placement the mutation would land before routing, so
    // the router would go looking for a GET route. A middleware must not change
    // what a request routes to, however rare the arrangement that notices.
    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::match(['HEAD'], '/head-only', fn (): string => 'head-only payload');

    $response = $this->head('/head-only');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toBe('')
        // What it costs: Router::prepareResponse() empties the body for the
        // HEAD it can now see, and BodyHashStrategy declines to hash an empty
        // one, so the response goes untagged. That is the degradation `model`
        // already takes at this position, and the cheaper of the two.
        ->and($response->headers->get('ETag'))->toBeNull();
});

it('routes a HEAD to the HEAD action beside a GET one on the same URI', function (): void {
    // The quieter half of the same mutation: no error, just the wrong action.
    // Route::get() registers a HEAD entry of its own, and RouteCollection keys
    // one route per method and URI, so the HEAD declaration has to come second
    // to hold that slot — which it does, until the method is rewritten out from
    // under the router.
    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::get('/both', fn (): Response => response('payload', 200, ['X-Action' => 'get']));
    Route::match(['HEAD'], '/both', fn (): Response => response('', 200, ['X-Action' => 'head']));

    expect($this->head('/both')->headers->get('X-Action'))->toBe('head');
});

it('gives an error response no validator and no body on a HEAD request', function (): void {
    // Laravel's routing Pipeline catches the exception and renders it inside
    // $next(), so the 500 comes back to the middleware as an ordinary response.
    // What this covers is that an error never gets a validator and that the
    // middleware's single exit still empties the body — not the finally block,
    // which is not reached on this path.
    Route::middleware('conditional')->get('/boom', function (): never {
        throw new RuntimeException('kaboom');
    });

    $response = $this->head('/boom');

    expect($response->status())->toBe(500)
        ->and($response->headers->get('ETag'))->toBeNull()
        ->and($response->getContent())->toBe('');
});

it('restores the request method when an exception escapes the middleware', function (): void {
    $captured = null;

    Route::middleware('conditional')->get('/boom', function () use (&$captured): never {
        $captured = request();

        throw new RuntimeException('kaboom');
    });

    // Without exception handling the throw propagates out through handle(),
    // which is the only path where the finally block is load-bearing.
    try {
        $this->withoutExceptionHandling()->head('/boom');
    } catch (RuntimeException) {
        // Expected — the assertion is about what the finally left behind.
    }

    expect($captured?->getMethod())->toBe('HEAD');
});
