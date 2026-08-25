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

it('does not suppress the size ceiling for a strategy that could not answer early', function (): void {
    config()->set('laravel-conditional-requests.max_response_bytes', 8);

    ConditionalRequests::extend('declining', fn (): ValidatorStrategy => decliningStrategy());

    Route::middleware('conditional:declining')->get('/large', fn () => response(str_repeat('a', 64)));

    // Implementing RequestValidatorStrategy is not the claim; producing a
    // validator is. This one declines before the controller, so fromResponse()
    // is asked the ordinary way — against exactly the response the ceiling
    // exists to keep out of a hash.
    $this->get('/large')->assertHeaderMissing('ETag');
});

it('does not suppress the streamed-response rule for a strategy that could not answer early', function (): void {
    ConditionalRequests::extend('declining', fn (): ValidatorStrategy => decliningStrategy());

    Route::middleware('conditional:declining')->get('/stream', fn (): StreamedResponse => new StreamedResponse(function (): void {
        echo 'chunk';
    }));

    $this->get('/stream')->assertHeaderMissing('ETag');
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

it('gives a declining strategy the same tag on HEAD as on GET', function (): void {
    ConditionalRequests::extend('declining', fn (): ValidatorStrategy => decliningStrategy());

    Route::middleware('conditional:declining')->get('/articles', fn () => response('body'));

    // RFC 9110 §9.3.2: a HEAD carries the headers its GET would. A strategy
    // that declines before the controller hashes the rendered body afterwards,
    // so it needs the GET mutation exactly as `body` does. Keyed off the
    // interface instead of the answer, the controller sees a real HEAD,
    // prepare() nulls the body, and every such response carries one constant
    // tag — the hash of "" — which a client can then be handed a 304 against
    // for a resource it has never fetched.
    $expected = '"'.hash('xxh128', 'body').'"';

    expect($this->get('/articles')->headers->get('ETag'))->toBe($expected)
        ->and($this->head('/articles')->headers->get('ETag'))->toBe($expected);
});

/**
 * A strategy of the shape the README invites: answer from the request when it
 * can, hash the rendered body when it cannot. The declining half is the one
 * under test, so it always declines.
 */
function decliningStrategy(): RequestValidatorStrategy
{
    return new class implements RequestValidatorStrategy
    {
        public function fromRequest(Request $request): ?Validator
        {
            return null;
        }

        public function fromResponse(Request $request, Response $response): ?Validator
        {
            return new Validator(hash('xxh128', (string) $response->getContent()));
        }
    };
}
