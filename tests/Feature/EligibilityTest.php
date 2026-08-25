<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('skips every response when disabled', function (): void {
    config()->set('laravel-conditional-requests.enabled', false);

    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);

    $this->get('/articles')->assertHeaderMissing('ETag');
});

it('hands the controller an untouched request when disabled', function (): void {
    config()->set('laravel-conditional-requests.enabled', false);

    $seen = null;

    Route::middleware('conditional')->get('/articles', function () use (&$seen) {
        $seen = request()->method();

        return response('body');
    });

    $this->head('/articles');

    expect($seen)->toBe('HEAD');
});

it('treats a truthy non-boolean enabled value as enabled', function (): void {
    config()->set('laravel-conditional-requests.enabled', 1);

    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);

    $this->get('/articles')->assertHeader('ETag');
});

it('skips responses that are not successful', function (): void {
    Route::middleware('conditional')->get('/missing', fn () => response('gone', 404));

    $this->get('/missing')->assertHeaderMissing('ETag');
});

it('skips methods outside the configured list', function (): void {
    Route::middleware('conditional')->post('/articles', fn (): array => ['title' => 'Hello']);

    $this->post('/articles')->assertHeaderMissing('ETag');
});

it('leaves an ETag the application already set', function (): void {
    Route::middleware('conditional')->get('/articles', fn () => response('body')->setEtag('application-owned'));

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"application-owned"');
});

it('skips streamed responses', function (): void {
    Route::middleware('conditional')->get('/stream', fn (): StreamedResponse => new StreamedResponse(function (): void {
        echo 'chunk';
    }));

    $this->get('/stream')->assertHeaderMissing('ETag');
});

it('skips binary file responses', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'crt');
    file_put_contents($path, 'file contents');

    Route::middleware('conditional')->get('/download', fn (): BinaryFileResponse => new BinaryFileResponse($path));

    $this->get('/download')->assertHeaderMissing('ETag');

    unlink($path);
});

it('skips responses larger than the configured ceiling', function (): void {
    config()->set('laravel-conditional-requests.max_response_bytes', 8);

    Route::middleware('conditional')->get('/large', fn () => response(str_repeat('a', 64)));

    $this->get('/large')->assertHeaderMissing('ETag');
});

it('skips routes excluded by name', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['admin.*']);

    Route::middleware('conditional')->get('/admin/stats', fn () => response('body'))->name('admin.stats');

    $this->get('/admin/stats')->assertHeaderMissing('ETag');
});

it('skips routes excluded by URI pattern', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['internal/*']);

    Route::middleware('conditional')->get('/internal/metrics', fn () => response('body'));

    $this->get('/internal/metrics')->assertHeaderMissing('ETag');
});

it('still tags a route that matches no exclusion', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['internal/*']);

    Route::middleware('conditional')->get('/articles', fn () => response('body'));

    $this->get('/articles')->assertHeader('ETag');
});
