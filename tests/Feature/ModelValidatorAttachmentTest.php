<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Facades\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

it('attaches the model validator to a plain response', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $etag = $this->get('/articles/1')->headers->get('ETag');
    $expected = Article::query()->findOrFail(1)->conditionalValidator(Request::create('/articles/1'));

    expect($etag)->toBe('"'.(string) $expected?->etag.'"');
});

it('changes the tag when the record changes', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $before = $this->get('/articles/1')->headers->get('ETag');

    Article::query()->findOrFail(1)->update(['version' => 2]);

    $this->get('/articles/1', ['If-None-Match' => $before])
        ->assertOk()
        ->assertJson(['title' => 'Hello']);
});

it('does not recompute a validator it already has', function (): void {
    $strategy = new class implements RequestValidatorStrategy
    {
        public int $responseCalls = 0;

        public function fromRequest(Request $request): ?Validator
        {
            return new Validator('counted');
        }

        public function fromResponse(Request $request, Response $response): ?Validator
        {
            $this->responseCalls++;

            return new Validator('counted');
        }
    };

    ConditionalRequests::extend('counting', fn (): ValidatorStrategy => $strategy);

    Route::middleware('conditional:counting')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"counted"')
        ->and($strategy->responseCalls)->toBe(0);
});

it('still attaches the validator when bindings are substituted after the middleware', function (): void {
    // Conditional ahead of SubstituteBindings: nothing to derive before the
    // controller, but the route parameter is a model by the time it returns, so
    // the response is tagged anyway. Only the compute saving is lost.
    $runs = 0;

    Route::middleware(['conditional:model', SubstituteBindings::class])
        ->get('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;

            return ['title' => $article->title];
        });

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);

    expect($runs)->toBe(2);
});

it('attaches a request-derived validator to a streamed response', function (): void {
    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('streamed-tag'));

    Route::middleware('conditional:probe-request')->get('/stream', fn (): StreamedResponse => new StreamedResponse(function (): void {
        echo 'chunk';
    }));

    expect($this->get('/stream')->headers->get('ETag'))->toBe('"streamed-tag"');
});

it('attaches a request-derived validator to a response over the size ceiling', function (): void {
    config()->set('laravel-conditional-requests.max_response_bytes', 8);

    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('large-tag'));

    Route::middleware('conditional:probe-request')->get('/large', fn () => response(str_repeat('a', 64)));

    expect($this->get('/large')->headers->get('ETag'))->toBe('"large-tag"');
});

it('still skips a response that already carries an ETag', function (): void {
    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('ours'));

    Route::middleware('conditional:probe-request')->get('/articles', fn () => response('body')->setEtag('application-owned'));

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"application-owned"');
});

it('still gives an error response no validator', function (): void {
    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('ours'));

    Route::middleware('conditional:probe-request')->get('/missing', fn () => response('gone', 404));

    $this->get('/missing')->assertHeaderMissing('ETag');
});

it('hands the controller a real HEAD request under a request-derived strategy', function (): void {
    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('head-tag'));

    $seen = null;

    Route::middleware('conditional:probe-request')->get('/articles', function () use (&$seen) {
        $seen = request()->method();

        return response('body');
    });

    $response = $this->head('/articles');

    // Design §7: the GET mutation exists so there is a body left to hash. A
    // strategy that never reads the body has no use for it.
    expect($seen)->toBe('HEAD')
        ->and($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe('"head-tag"');
});
