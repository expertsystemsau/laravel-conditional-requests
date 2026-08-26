<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Note;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

function writeRoute(string $middleware): void
{
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    Route::middleware([SubstituteBindings::class, $middleware])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);
}

it('refuses to guard a route whose strategy cannot answer before the controller, naming the route', function (): void {
    $this->withoutExceptionHandling();

    writeRoute('conditional:body,required');

    // The message must name the offending route, not just the strategy and
    // the contract — that is the part telling a developer where to look.
    expect(fn () => $this->put('/articles/1'))->toThrow(function (LogicException $e): void {
        expect($e->getMessage())
            ->toContain('the [body] validator strategy cannot produce a validator before the controller runs')
            ->toContain('PUT /articles/1');
    });
});

it('names the contract a strategy has to implement to be guardable', function (): void {
    $this->withoutExceptionHandling();

    writeRoute('conditional:body,required');

    $this->put('/articles/1');
})->throws(LogicException::class, RequestValidatorStrategy::class);

it('refuses to guard a route whose validators are weak', function (): void {
    $this->withoutExceptionHandling();

    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:required');

    $this->put('/articles/1', [], ['If-Match' => '"anything"']);
})->throws(LogicException::class, '[laravel-conditional-requests.weak]');

it('explains why a weak validator cannot be guarded', function (): void {
    $this->withoutExceptionHandling();

    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:required');

    $this->put('/articles/1');
})->throws(LogicException::class, 'RFC 9110 §13.1.1 requires strong comparison');

it('leaves a route without required alone when its strategy cannot answer', function (): void {
    writeRoute('conditional:body');

    $this->put('/articles/1')->assertOk();
});

it('leaves a route without required alone when its validators are weak', function (): void {
    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:model');

    $this->put('/articles/1')->assertOk();
});

it('refuses to evaluate an If-Match against weak validators without required too', function (): void {
    // Amended with the v0.3 write-path sweep. This previously asserted 412 and
    // called it "not an error, because the route never asked for the guard".
    // It is an error: weakness inverts the guard on this route just as surely
    // as on a `required` one. Every client sending the correct token — strong
    // or weak-prefixed — was refused with 412, and every client sending
    // nothing wrote freely, with nothing in either response to say why.
    $this->withoutExceptionHandling();

    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:model');

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->toStartWith('W/"');

    expect(fn () => $this->put('/articles/1', [], ['If-Match' => substr($etag, 2)]))
        ->toThrow(LogicException::class, '[laravel-conditional-requests.weak]');
});

it('raises the weak validator error for a weak prefixed If-Match too', function (): void {
    $this->withoutExceptionHandling();

    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:model');

    $this->put('/articles/1', [], ['If-Match' => 'W/"anything"']);
})->throws(LogicException::class, '[laravel-conditional-requests.weak]');

it('leaves a weak validators create guard alone', function (): void {
    // The error fires where the misconfiguration matters and nowhere else.
    // If-None-Match is compared weakly under §13.1.2, so a weak validator
    // satisfies it perfectly well and the create guard is not inverted.
    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:model');

    $this->put('/articles/1', [], ['If-None-Match' => '*'])->assertStatus(412);
});

it('guards a route whose custom strategy can answer before the controller', function (): void {
    app(ConditionalRequests::class)->extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('custom-tag'));

    writeRoute('conditional:probe-request,required');

    $this->put('/articles/1')->assertStatus(428);
    $this->put('/articles/1', [], ['If-Match' => '"custom-tag"'])->assertOk();
});

it('still rejects an unknown strategy name on the write path', function (): void {
    $this->withoutExceptionHandling();

    writeRoute('conditional:nope,required');

    $this->put('/articles/1');
})->throws(InvalidArgumentException::class, 'Conditional request strategy [nope] is not registered.');

it('refuses to guard a write route that binds more than one conditional record', function (): void {
    // PATCH /articles/{article}/comments/{comment} is the everyday shape. The
    // guard tracked the article while the controller wrote the comment, so the
    // client sending the comment's tag was refused and the client sending the
    // article's tag overwrote it.
    $this->withoutExceptionHandling();

    Note::create(['body' => 'Nested']);

    Route::middleware([SubstituteBindings::class, 'conditional:required'])
        ->patch('/articles/{article}/notes/{note}', fn (Article $article, Note $note): array => ['body' => $note->body]);

    expect(fn () => $this->patch('/articles/1/notes/1', [], ['If-Match' => '*']))
        ->toThrow(function (LogicException $e): void {
            expect($e->getMessage())
                ->toContain('articles/{article}/notes/{note}')
                ->toContain('[article, note]')
                ->toContain(ProvidesConditionalValidator::class);
        });
});

it('raises the ambiguity error whatever precondition the client sends', function (): void {
    // A client cannot hide the misconfiguration by sending the header that
    // happened to work, or by sending none at all.
    $this->withoutExceptionHandling();

    Note::create(['body' => 'Nested']);

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->patch('/articles/{article}/notes/{note}', fn (Article $article, Note $note): array => ['body' => $note->body]);

    foreach ([[], ['If-Match' => '"anything"'], ['If-None-Match' => '*']] as $headers) {
        expect(fn () => $this->patch('/articles/1/notes/1', [], $headers))
            ->toThrow(LogicException::class, 'binds more than one record implementing');
    }
});

it('leaves a nested read route tagging the first record', function (): void {
    // The read path's first-wins rule is documented and harmless: only the
    // write path asks which record it is protecting.
    Note::create(['body' => 'Nested']);

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}/notes/{note}', fn (Article $article, Note $note): array => ['body' => $note->body]);

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    expect($this->get('/articles/1/notes/1')->headers->get('ETag'))
        ->not->toBeNull()
        ->toBe($this->get('/articles/1')->headers->get('ETag'));
});
