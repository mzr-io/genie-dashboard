<?php

// Story 4.1 (AD-27): Platform\Period\PeriodResolver is the only implementation of FR-35. Nothing else computes a preset's window or
// chooses between a selection, a dashboard Date Range and a Block default; callers go through the resolver.

/**
 * @param  list<string>  $callers  files (relative to the scanned root) allowed to call PeriodResolver::resolve()
 * @return list<string> violations
 */
function periodViolations(string $root, array $callers): array
{
    $out = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(substr($path, strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');

        if (! str_ends_with($path, '.php') || str_starts_with($relative, 'Platform/Period/')) {
            continue;
        }

        $code = preg_replace_callback('~/\*.*?\*/~s', fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), (string) file_get_contents($path));
        $code = preg_replace('~(?m)^\s*(//|#).*$~', '', (string) $code);

        foreach (explode("\n", (string) $code) as $i => $line) {
            $at = "{$relative}:".($i + 1);

            if (preg_match('/PeriodResolver::resolve\(/', $line) === 1 && ! in_array($relative, $callers, true)) {
                $out[] = "{$at} calls PeriodResolver::resolve()";
            }

            if (preg_match('/PeriodPreset::[A-Za-z0-9_]+->(window|previous)\(|->(window|previous)\(\s*\$today/', $line) === 1) {
                $out[] = "{$at} computes a preset window";
            }

            if (preg_match('/[\'"](this_week|last_week|this_month|last_month|this_quarter|last_quarter|this_year|last_year|last_7_days|last_30_days|last_90_days)[\'"]/', $line) === 1) {
                $out[] = "{$at} names a period preset";
            }

            if (preg_match('/PeriodBehaviour::(FollowDashboard|OwnSelector)/', $line) === 1 && ! in_array($relative, $callers, true)) {
                $out[] = "{$at} decides on a period behaviour";
            }
        }
    }

    return $out;
}

it('keeps every period decision inside Platform\Period, called through the wizard helper and the viewed-result read', function () {
    expect(periodViolations(dirname(__DIR__, 2).'/app', ['Modules/Blocks/Application/DraftPeriod.php', 'Modules/Results/Application/ViewBlockResult.php']))->toBe([]);
});

it('fails a second implementation and names file and line', function () {
    $root = sys_get_temp_dir().'/period-arch-'.bin2hex(random_bytes(4));
    mkdir("{$root}/Modules/Dash", 0777, true);
    file_put_contents("{$root}/Modules/Dash/Own.php", "<?php\n\$p = \$choice ?? 'this_month';\nif (\$b === PeriodBehaviour::OwnSelector) {}\nPeriodResolver::resolve(\$a);\n");

    $all = implode("\n", periodViolations($root, []));

    expect($all)->toContain('Modules/Dash/Own.php:2')->toContain('Modules/Dash/Own.php:3')->toContain('Modules/Dash/Own.php:4');

    array_map('unlink', glob("{$root}/Modules/Dash/*"));
    rmdir("{$root}/Modules/Dash");
    rmdir("{$root}/Modules");
    rmdir($root);
});
