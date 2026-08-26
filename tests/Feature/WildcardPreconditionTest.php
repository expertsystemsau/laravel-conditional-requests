<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Blind;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);

    // A binder that answers "absent" instead of aborting with a 404. Ordinary
    // implicit binding never lets the middleware see a missing resource, so a
    // create guard has nothing to guard without this.
    Route::bind('article', fn (string $value): ?Article => Article::query()->find($value));

    // The action is deliberately not type-hinted against Article: an implicit
    // binding on a signature parameter would resolve the record a second time
    // and 404 on the very case this file exists to exercise.
    Route::middleware([SubstituteBindings::class, 'conditional:required'])
        ->put('/articles/{article}', function (Request $request): array {
            $existing = $request->route('article');

            if ($existing instanceof Article) {
                $existing->update(['version' => (int) $existing->version + 1]);

                return ['status' => 'updated'];
            }

            Article::query()->create([
                'id' => (int) $request->segment(2),
                'title' => 'Created',
                'version' => 1,
            ]);

            return ['status' => 'created'];
        });

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Request $request): array => ['title' => 'Hello']);
});

it('lets an If-Match wildcard through when the resource exists', function (): void {
    $this->put('/articles/1', [], ['If-Match' => '*'])
        ->assertOk()
        ->assertJson(['status' => 'updated']);
});

it('refuses an If-Match wildcard when the resource does not exist', function (): void {
    $this->put('/articles/999', [], ['If-Match' => '*'])->assertStatus(412);

    expect(Article::query()->count())->toBe(1);
});

it('refuses an If-None-Match wildcard when the resource already exists', function (): void {
    // MDN's first-upload race: two clients creating the same resource should
    // produce one success and one 412, not a silent duplicate or overwrite.
    $this->put('/articles/1', [], ['If-None-Match' => '*'])->assertStatus(412);
});

it('lets an If-None-Match wildcard create a resource that does not exist', function (): void {
    $this->put('/articles/999', [], ['If-None-Match' => '*'])
        ->assertOk()
        ->assertJson(['status' => 'created']);

    expect(Article::query()->count())->toBe(2);
});

it('gives two racing creates one success and one refusal', function (): void {
    $this->put('/articles/999', [], ['If-None-Match' => '*'])->assertOk();
    $this->put('/articles/999', [], ['If-None-Match' => '*'])->assertStatus(412);

    expect(Article::query()->where('id', 999)->count())->toBe(1);
});

it('accepts an If-None-Match wildcard as the precondition a required route demands', function (): void {
    $this->put('/articles/999', [], ['If-None-Match' => '*'])->assertOk();
});

it('tolerates whitespace around a wildcard', function (): void {
    $this->put('/articles/1', [], ['If-Match' => '  *  '])->assertOk();
});

it('treats a quoted asterisk as an entity tag rather than a wildcard', function (): void {
    // werk365 checks for the quoted form and so misses the real one — defect
    // #3. The bare wildcard above passes; this one is a tag that matches
    // nothing, so it is refused.
    $this->put('/articles/1', [], ['If-Match' => '"*"'])->assertStatus(412);
});

it('evaluates If-Match first when both wildcards are sent', function (): void {
    // §13.2.2. If-None-Match: * alone would refuse this; If-Match: * allows it.
    $this->put('/articles/1', [], ['If-Match' => '*', 'If-None-Match' => '*'])->assertOk();
});

it('demands a real precondition for a non wildcard If-None-Match on a required route', function (): void {
    // Amended with the v0.3 write-path sweep. This previously asserted 412,
    // which is §13.2.2's answer for a concrete If-None-Match that matches — but
    // the route is `required`, and a concrete If-None-Match is not one of the
    // two field values that satisfy the flag. Whatever it names, the answer is
    // now 428; the comparison below still decides a route without `required`.
    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    $this->put('/articles/1', [], ['If-None-Match' => $etag])->assertStatus(428);
});

it('refuses a non wildcard If-None-Match naming the current version', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->patch('/articles/{article}', fn (): array => ['status' => 'updated']);

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    $this->patch('/articles/1', [], ['If-None-Match' => $etag])->assertStatus(412);
    $this->patch('/articles/1', [], ['If-None-Match' => '"other"'])->assertOk();
});

it('refuses a create over a record that exists but yields no validator', function (): void {
    // The sharpest fail-open in the create guard: the row is there,
    // conditionalVersionColumns() is empty so nothing can be compared, and the
    // one precondition whose entire job is refusing to write over an existing
    // resource used to pass — overwriting it.
    Blind::query()->create(['id' => 7, 'title' => 'IMPORTANT-DATA']);

    Route::bind('blind', fn (string $value): ?Blind => Blind::query()->find($value));

    Route::middleware([SubstituteBindings::class, 'conditional:required'])
        ->put('/blind/{blind}', function (Request $request): array {
            $existing = $request->route('blind');

            if ($existing instanceof Blind) {
                $existing->update(['title' => 'OVERWRITTEN']);

                return ['status' => 'updated'];
            }

            Blind::query()->create(['id' => (int) $request->segment(2), 'title' => 'Created']);

            return ['status' => 'created'];
        });

    $this->put('/blind/7')->assertStatus(428);
    $this->put('/blind/7', [], ['If-Match' => '*'])->assertStatus(412);
    $this->put('/blind/7', [], ['If-None-Match' => '*'])->assertStatus(412);

    expect(Blind::query()->findOrFail(7)->title)->toBe('IMPORTANT-DATA');

    // A genuinely absent record on the same route still creates: the guard
    // fails closed on "cannot tell", not on every null validator.
    $this->put('/blind/8', [], ['If-None-Match' => '*'])
        ->assertOk()
        ->assertJson(['status' => 'created']);
});
