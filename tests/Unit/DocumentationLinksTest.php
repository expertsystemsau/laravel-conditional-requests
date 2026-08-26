<?php

declare(strict_types=1);

/**
 * Eight documentation pages and the `.github/*.md` files cross-link each
 * other, and the hazard register is linked by anchor from a dozen places. A
 * broken `#h7` is invisible in review and permanent once merged, so it is
 * checked here rather than by eye.
 */
it('has no broken internal documentation links', function (): void {
    expect(brokenLinks())->toBe([]);
});

/**
 * The documents this checks.
 *
 * `docs/design/` and `docs/plans/` are deliberately outside it. They are the
 * project's historical record — an approved design and six implementation
 * plans, each written before the layout they describe existed — and editing
 * their prose to satisfy a link checker would falsify the record rather than
 * fix anything. Nothing a user reads links into them by anchor.
 *
 * @return list<string>
 */
function documentationFiles(): array
{
    $root = dirname(__DIR__, 2);

    /** @var list<string> $files */
    $files = [$root.'/README.md', $root.'/CHANGELOG.md'];

    foreach ([$root.'/docs/*.md', $root.'/.github/*.md'] as $pattern) {
        foreach ((array) glob($pattern) as $file) {
            if (is_string($file)) {
                $files[] = $file;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Every internal link target that does not resolve, as `file → target: why`.
 *
 * @return list<string>
 */
function brokenLinks(): array
{
    $root = dirname(__DIR__, 2);

    /** @var list<string> $broken */
    $broken = [];

    foreach (documentationFiles() as $file) {
        $contents = (string) file_get_contents($file);
        $from = ltrim(str_replace($root, '', $file), '/');

        foreach (linkTargets($contents) as $target) {
            $failure = resolveLink($file, $target);

            if ($failure !== null) {
                $broken[] = $from.' → '.$target.': '.$failure;
            }
        }
    }

    return $broken;
}

/**
 * The `](…)` targets in one document that are worth resolving — external
 * schemes and bare protocol-relative URLs are somebody else's problem.
 *
 * @return list<string>
 */
function linkTargets(string $contents): array
{
    // Strip fenced code blocks first: a sample containing `](…)` is an example,
    // not a link, and the renderer does not turn it into one either.
    $prose = (string) preg_replace('/^```.*?^```/ms', '', $contents);

    preg_match_all('/]\(([^)\s]+)\)/', $prose, $matches);

    /** @var list<string> $targets */
    $targets = [];

    foreach ($matches[1] as $target) {
        if (preg_match('#^(https?:|mailto:|//)#', $target) === 1) {
            continue;
        }

        // GitHub resolves `../../contributors` from a repository-root README
        // against the repository URL rather than the working tree, so it is a
        // working link that has no file behind it.
        if (str_starts_with($target, '../../')) {
            continue;
        }

        $targets[] = $target;
    }

    return $targets;
}

/**
 * Why a target does not resolve, or null when it does.
 *
 * A target with no path before the `#` is an anchor into the linking document
 * itself, which is how every same-page cross-reference is written.
 */
function resolveLink(string $source, string $target): ?string
{
    [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);

    $file = $path === '' || $path === null
        ? $source
        : realpath(dirname($source).'/'.$path);

    if ($file === false || ! file_exists($file)) {
        return 'no such file';
    }

    if ($fragment === null || $fragment === '') {
        return null;
    }

    if (is_dir($file)) {
        return 'fragment on a directory link';
    }

    return in_array($fragment, anchorsIn((string) file_get_contents($file)), true)
        ? null
        : 'no such anchor';
}

/**
 * Every anchor a GitHub-rendered document offers: one per heading, plus any
 * explicit `<a id="…">` a page writes for itself.
 *
 * @return list<string>
 */
function anchorsIn(string $contents): array
{
    $prose = (string) preg_replace('/^```.*?^```/ms', '', $contents);

    /** @var list<string> $anchors */
    $anchors = [];

    preg_match_all('/^#{1,6}\s+(.*)$/m', $prose, $headings);

    foreach ($headings[1] as $heading) {
        $anchors[] = headingAnchor($heading);
    }

    preg_match_all('/<a\s+id="([^"]+)"/', $prose, $explicit);

    foreach ($explicit[1] as $id) {
        $anchors[] = $id;
    }

    return $anchors;
}

/**
 * GitHub's rule: lower-case, drop everything that is not a word character, a
 * space or a hyphen, then turn spaces into hyphens.
 */
function headingAnchor(string $heading): string
{
    $anchor = mb_strtolower(trim($heading));

    $anchor = (string) preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $anchor);

    return (string) preg_replace('/\s/u', '-', $anchor);
}
