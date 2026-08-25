<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Note;

it('persists an article fixture with a version column', function (): void {
    $article = Article::create(['title' => 'Hello', 'version' => 1]);

    expect($article->getKey())->toBe(1)
        ->and(Article::query()->find(1)?->title)->toBe('Hello')
        ->and($article->getAttributes()['version'])->toBe(1);
});

it('stores the raw updated_at attribute as a string', function (): void {
    // The trait in Task 2 fingerprints the raw attribute rather than the cast
    // one, so this is load-bearing: raw must be a scalar the tag can consume.
    $article = Article::create(['title' => 'Hello']);

    expect($article->getAttributes()['updated_at'])->toBeString();
});

it('persists a note fixture in its own table', function (): void {
    $note = Note::create(['body' => 'Remember this']);

    expect($note->getTable())->toBe('notes')
        ->and(Note::query()->count())->toBe(1);
});
