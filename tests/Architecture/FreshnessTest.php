<?php

// Story 4.3: `Results\Contracts\Freshness` is the only place the staleness rule (2 x the refresh interval) exists.

/**
 * @return list<string> violations
 */
function freshnessViolations(string $root): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! str_ends_with($path, '.php') || str_ends_with($path, 'Results/Contracts/Freshness.php')) {
            continue;
        }

        $code = (string) preg_replace_callback('~/\*.*?\*/~s', fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), (string) file_get_contents($path));
        $code = (string) preg_replace('~(?m)^\s*(//|#).*$~', '', $code);

        foreach (explode("\n", $code) as $i => $line) {
            $at = basename($path).':'.($i + 1);

            if (preg_match('/function\s+(isStale|staleAt)\b/', $line) === 1) {
                $out[] = "{$at} defines a staleness rule";
            }

            if (preg_match('/\b2\s*\*\s*\$\w*(interval|Interval)|\$\w*(interval|Interval)\w*\s*\*\s*2\b|last_success_at.{0,60}\*\s*2\b/', $line) === 1) {
                $out[] = "{$at} multiplies a refresh interval by two";
            }
        }
    }

    return $out;
}

it('keeps the staleness rule in Freshness alone', function () {
    expect(freshnessViolations(dirname(__DIR__, 2).'/app'))->toBe([]);
});

it('fails a second rule and names file and line', function () {
    $root = sys_get_temp_dir().'/fresh-arch-'.bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents("{$root}/Own.php", "<?php\nfunction isStale() {}\n\$late = \$now > \$last + 2 * \$interval;\n");

    $all = implode("\n", freshnessViolations($root));

    expect($all)->toContain('Own.php:2')->toContain('Own.php:3');

    unlink("{$root}/Own.php");
    rmdir($root);
});
