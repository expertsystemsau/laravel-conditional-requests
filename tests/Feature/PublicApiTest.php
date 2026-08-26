<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\LockableValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Validators\BodyHashStrategy;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Every publicly documented symbol, as `Class::member`, sorted.
 *
 * Anything not on this list is internal and carries @internal in its source;
 * the second example below asserts that the two lists partition the package.
 *
 * Changing this list is a semver decision. Make it deliberately, update
 * docs/api.md in the same commit, and say in the changelog which of major,
 * minor or patch it is.
 */
const FROZEN_SURFACE = [
    'BodyHashStrategy::__construct(string $algorithm = \'xxh128\', bool $weak = false)',
    'BodyHashStrategy::fromResponse(Request $request, Response $response): ?Validator',
    'Conditional::handle(Request $request, Closure $next, string ...$flags): Response',
    'ConditionalRequests::extend(string $name, Closure $resolver): void',
    'ConditionalRequests::strategy(string $name): ValidatorStrategy',
    'HasConditionalValidator::conditionalLastModifiedColumn(): ?string',
    'HasConditionalValidator::conditionalValidator(Request $request): ?Validator',
    'HasConditionalValidator::conditionalVersionColumns(): array',
    'LockTimeoutException::MESSAGE_KEY',
    // Reflection normalises a union's member order, so this reads `string|int`
    // where the source declares `int|string`. The set is what is frozen.
    'LockTimeoutException::__construct(string $message = \'\', ?Throwable $previous = null, string|int $retryAfter = 1)',
    'LockableValidatorStrategy::lockAndRefresh(Request $request, Model $target): ?Model',
    'LockableValidatorStrategy::lockTarget(Request $request): ?Model',
    'ModelStrategy::__construct(bool $weak = false, bool $lastModified = true)',
    'ModelStrategy::fromRequest(Request $request): ?Validator',
    'ModelStrategy::fromResponse(Request $request, Response $response): ?Validator',
    'ModelStrategy::lockAndRefresh(Request $request, Model $target): ?Model',
    'ModelStrategy::lockTarget(Request $request): ?Model',
    'ModelStrategy::targetExists(Request $request): ?bool',
    'PreconditionFailedException::MESSAGE_KEY',
    'PreconditionRequiredException::MESSAGE_KEY',
    'ProvidesConditionalValidator::conditionalValidator(Request $request): ?Validator',
    'RequestValidatorStrategy::fromRequest(Request $request): ?Validator',
    'RequestValidatorStrategy::targetExists(Request $request): ?bool',
    'Validator::$etag',
    'Validator::$lastModified',
    'Validator::$weak',
    'Validator::__construct(string $etag, bool $weak = false, ?DateTimeInterface $lastModified = null)',
    'Validator::header(): string',
    'ValidatorStrategy::fromResponse(Request $request, Response $response): ?Validator',
];

it('has not changed its public API surface', function (): void {
    expect(publicSurface())->toBe(FROZEN_SURFACE);
});

it('marks every class outside the frozen surface @internal', function (): void {
    expect(unfrozenClassesWithoutInternalAnnotation())->toBe([]);
});

it('freezes the configuration keys', function (): void {
    expect(array_keys(require __DIR__.'/../../config/laravel-conditional-requests.php'))
        ->toBe([
            'enabled',
            'strategy',
            'hash',
            'weak',
            'last_modified',
            'max_response_bytes',
            'methods',
            'exclude',
            'lock_timeout',
        ]);
});

it('freezes the middleware alias and the built-in strategy names', function (): void {
    expect(app(Router::class)->getMiddleware()['conditional'] ?? null)->toBe(Conditional::class);

    $registry = app(ConditionalRequests::class);

    expect($registry->strategy('body'))->toBeInstanceOf(BodyHashStrategy::class)
        ->and($registry->strategy('model'))->toBeInstanceOf(ModelStrategy::class);
});

it('freezes the publish tags', function (): void {
    $tags = array_values(array_filter(
        ServiceProvider::publishableGroups(),
        static fn (string $tag): bool => str_starts_with($tag, 'laravel-conditional-requests'),
    ));

    sort($tags);

    expect($tags)->toBe([
        'laravel-conditional-requests',
        'laravel-conditional-requests-assets',
        'laravel-conditional-requests-config',
        'laravel-conditional-requests-lang',
    ]);
});

it('freezes the translation keys', function (): void {
    expect(array_keys(require __DIR__.'/../../lang/en/messages.php'))
        ->toBe(['precondition_failed', 'precondition_required', 'lock_timeout']);
});

it('freezes the strategy contract inheritance chain', function (): void {
    expect(is_subclass_of(RequestValidatorStrategy::class, ValidatorStrategy::class))->toBeTrue()
        ->and(is_subclass_of(LockableValidatorStrategy::class, RequestValidatorStrategy::class))->toBeTrue()
        ->and(is_subclass_of(LockableValidatorStrategy::class, ValidatorStrategy::class))->toBeTrue();
});

/**
 * Every class in src/, by fully-qualified name, derived from the path rather
 * than from a classmap or the container so that a file nobody wires up is
 * still seen.
 *
 * @return list<string>
 */
function sourceClasses(): array
{
    $root = dirname(__DIR__, 2).'/src';

    /** @var list<string> $classes */
    $classes = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);

        $classes[] = 'ExpertSystems\\ConditionalRequests\\'.str_replace('/', '\\', $relative);
    }

    sort($classes);

    return $classes;
}

/**
 * The package's frozen surface, reflected out of the source.
 *
 * @return list<string>
 */
function publicSurface(): array
{
    /** @var list<string> $surface */
    $surface = [];

    foreach (sourceClasses() as $class) {
        $reflection = new ReflectionClass($class);

        if (isAnnotatedInternal($reflection->getDocComment())) {
            continue;
        }

        $surface = [...$surface, ...surfaceOf($reflection)];
    }

    sort($surface);

    return array_values(array_unique($surface));
}

/**
 * The frozen members one reflected class or trait contributes.
 *
 * The trait needs its own path. The type-coverage plugin skips any file
 * containing the string `trait `, so HasConditionalValidator is covered by
 * PHPStan alone and a green `test:types` is not evidence about it — reflecting
 * it through a class that actually uses it is the only way to assert its
 * members here. Its two protected methods are documented extension points and
 * are frozen with the public one; a trait's private members are not.
 *
 * @param  ReflectionClass<object>  $reflection
 * @return list<string>
 */
function surfaceOf(ReflectionClass $reflection): array
{
    if ($reflection->isTrait()) {
        return traitSurface($reflection);
    }

    $name = $reflection->getShortName();

    /** @var list<string> $members */
    $members = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }

        if (isAnnotatedInternal($method->getDocComment())) {
            continue;
        }

        $members[] = $name.'::'.signature($method);
    }

    foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }

        $members[] = $name.'::'.$constant->getName();
    }

    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }

        $members[] = $name.'::$'.$property->getName();
    }

    return $members;
}

/**
 * @param  ReflectionClass<object>  $trait
 * @return list<string>
 */
function traitSurface(ReflectionClass $trait): array
{
    $file = $trait->getFileName();
    $name = $trait->getShortName();

    /** @var list<string> $members */
    $members = [];

    $user = new ReflectionClass(Article::class);

    foreach ($user->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->getFileName() !== $file || isAnnotatedInternal($method->getDocComment())) {
            continue;
        }

        $members[] = $name.'::'.signature($method);
    }

    return $members;
}

/**
 * Every class in src/ that contributes nothing to the frozen surface and does
 * not say so in its own docblock. Green means the two lists partition the
 * package with nothing falling between them.
 *
 * @return list<string>
 */
function unfrozenClassesWithoutInternalAnnotation(): array
{
    /** @var list<string> $unfrozen */
    $unfrozen = [];

    foreach (sourceClasses() as $class) {
        $reflection = new ReflectionClass($class);

        if (isAnnotatedInternal($reflection->getDocComment())) {
            continue;
        }

        if (surfaceOf($reflection) === []) {
            $unfrozen[] = $class;
        }
    }

    return $unfrozen;
}

function isAnnotatedInternal(string|false $docblock): bool
{
    return is_string($docblock) && str_contains($docblock, '@internal');
}

/**
 * One method rendered as `name(type $param = default, …): returnType`, with
 * short class names so the frozen list stays readable.
 */
function signature(ReflectionMethod $method): string
{
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => renderParameter($parameter),
        $method->getParameters(),
    );

    $returns = $method->getReturnType();

    return $method->getName()
        .'('.implode(', ', $parameters).')'
        .($returns instanceof ReflectionType ? ': '.renderType($returns) : '');
}

function renderParameter(ReflectionParameter $parameter): string
{
    $type = $parameter->getType();

    return trim(
        ($type instanceof ReflectionType ? renderType($type).' ' : '')
        .($parameter->isVariadic() ? '...' : '')
        .'$'.$parameter->getName()
        .($parameter->isDefaultValueAvailable() && ! $parameter->isVariadic()
            ? ' = '.renderDefault($parameter)
            : ''),
    );
}

function renderDefault(ReflectionParameter $parameter): string
{
    $value = $parameter->getDefaultValue();

    return match (true) {
        $value === null => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        is_string($value) => "'".$value."'",
        is_int($value), is_float($value) => (string) $value,
        is_array($value) => '[]',
        default => '?',
    };
}

function renderType(ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();

        if (! $type->isBuiltin() && str_contains($name, '\\')) {
            $name = substr((string) strrchr($name, '\\'), 1);
        }

        return ($type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' : '').$name;
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $glue = $type instanceof ReflectionUnionType ? '|' : '&';

        return implode($glue, array_map(renderType(...), $type->getTypes()));
    }

    return (string) $type;
}
