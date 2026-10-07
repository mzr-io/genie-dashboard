<?php

use Tests\Security\Support\PestTitles;

// Story 1.25: the security coverage manifest. Each guarantee Epic 1 promises is listed against the
// tests that prove it, by full title. The manifest fails when a listed test no longer exists (renamed,
// moved, commented out or deleted), is skipped or todo, or its file is not part of the Pest group
// `security`, so a guarantee cannot lose its proof silently. A new tenant table without a leak test
// fails in tests/Database/TenantLeakCoverageTest.php.
//
// Entries are `file (relative to tests/) => [full test title, ...]`.

/** @return array<string, array{tests?: array<string, list<string>>, not_applicable?: string}> */
function securityManifest(): array
{
    return [
        'cross-tenant RLS leak, per tenant table' => ['tests' => [
            'Database/RowLevelSecurityTest.php' => [
                'never returns another Workspace\'s rows from any tenant table',
                'returns zero rows from every tenant table when no context is set',
                'protects every tenant table with non-null workspace_id, ENABLE and FORCE ROW LEVEL SECURITY and the context policy',
            ],
            'Database/PoolingTest.php' => ['keeps interleaved requests for Workspaces A and B apart on one server connection'],
            'Database/WorkspaceSwitchTest.php' => ['answers 404 for an object ID of another Workspace in every tenant table that exists, and 200 for its own'],
            'Database/TenantLeakCoverageTest.php' => ['passes the context-free and cross-Workspace leak checks for every tenant table'],
        ]],
        'CSRF on sign-in' => ['tests' => [
            'Security/CsrfTest.php' => [
                'refuses a sign-in post without a CSRF token with 419 and signs nobody in',
                'lets a sign-in post that carries the session token reach the sign-in flow, not the throttle',
            ],
        ]],
        'CSRF on area change' => ['not_applicable' => 'not applicable until an area-switch route exists'],
        'CSRF on Workspace switch' => ['tests' => [
            'Security/CsrfTest.php' => ['refuses a Workspace switch post without a CSRF token with 419'],
        ]],
        'CSRF on sign-out, password reset, invitation acceptance and profile update' => ['tests' => [
            'Security/CsrfTest.php' => [
                'refuses a sign-out post without a CSRF token with 419 and keeps the session',
                'refuses a password-reset submit without a CSRF token with 419',
                'refuses an invitation acceptance without a CSRF token with 419',
                'refuses a profile update without a CSRF token with 419',
            ],
        ]],
        'no area-change route exists' => ['tests' => [
            'Security/AreaChangeRouteTest.php' => [
                'has no route other than the Workspace switch that switches, elevates or changes area, mode or role',
                'writes the session area only at sign-in and in the Workspace switch',
            ],
        ]],
        'session rotation on sign-in' => ['tests' => [
            'Database/SignInTest.php' => ['signs a User in: rotated session, Workspace and area stored, membership stamped, event recorded, overview reachable'],
            'Feature/Auth/SignInTest.php' => ['signs in a User, stores the Workspace and area in a rotated session and lands on overview'],
        ]],
        'session rotation on Workspace switch' => ['tests' => [
            'Database/WorkspaceSwitchTest.php' => ['regenerates the session ID on a valid switch and leaves it alone on a refused one'],
        ]],
        'session rotation on password reset' => ['tests' => [
            'Feature/Auth/PasswordResetFlowTest.php' => ['destroys the old row of the current session when it is regenerated'],
            'Database/PasswordResetTest.php' => ['resets the password, deletes the other sessions as role app and records identity.password.reset in the Workspace'],
        ]],
        'invitation single use' => ['tests' => [
            'Database/WorkspaceProvisioningTest.php' => [
                'refuses a second use with the neutral page, a security event and no second membership',
                'lets only one of two uses of the same link win',
                'serialises two real concurrent uses: the second blocks on the row lock and is refused',
            ],
        ]],
        'invitation permission cap' => ['tests' => [
            'Database/InviteUsersTest.php' => [
                'refuses a permission the inviter does not hold with 403 access.permission_not_held, before looking at the password, and creates nothing',
                'caps an Admin invitation at the permissions the inviter holds at acceptance time',
            ],
        ]],
        'last users.manage holder' => ['tests' => [
            'Database/MemberAccessTest.php' => ['refuses taking users.manage from, or downgrading, the last active Admin holder (the rule itself, under the lock)'],
            'Database/MemberStatusTest.php' => ['refuses deactivating the last active users.manage holder with 409 access.last_users_manage_holder (the shared rule, under the lock)'],
        ]],
        'signed job tamper' => ['tests' => [
            'Feature/Queue/SignedJobTest.php' => ['never unserializes or runs a tampered payload, logs a security event and does not retry'],
            'Unit/JobSignerTest.php' => ['rejects a payload whose signed fields changed'],
            'Database/WorkspaceJobTest.php' => ['carries workspace_id in the serialised command, so the job signature covers it'],
        ]],
        'SSRF: every undeniable address class, odd IP spellings and mixed answers are denied, whatever the grants' => ['tests' => [
            'Unit/EgressGuardTest.php' => [
                'denies the whole request as blocked_address when any resolved address is undeniable',
                'classifies every spelling of a blocked IP literal and never resolves it',
                'never lets a grant lift an undeniable class',
                'classifies the deployment CIDRs as undeniable, even with a grant that covers them',
            ],
            'Unit/BlockedAddressClassTest.php' => ['classifies an address as undeniable, whatever a grant says'],
        ]],
        'SSRF: a private range is allowed only for the Workspace holding an operator grant' => ['tests' => [
            'Unit/EgressGuardTest.php' => ['denies a private address without a grant as host_not_allowlisted, and allows it for the Workspace holding the grant only'],
            'Database/EgressGrantsTest.php' => [
                'allows a granted private range for that Workspace only',
                'refuses a CIDR that is undeniable, overlaps one, overlaps the deployment, is public, is malformed or is already granted, and writes nothing',
                'records a denial as a connector.egress.blocked security event with reason, host and port and never an address',
            ],
        ]],
        'SSRF: pinned connection, rebinding, redirects and proxy variables' => ['tests' => [
            'Database/EgressTransportTest.php' => [
                'connects to exactly the address the guard checked: the host and port are pinned with CURLOPT_RESOLVE',
                'resolves the name once: a name that answers public and then loopback never reaches loopback (rebinding)',
                'refuses a redirect to another origin, a non-allowlisted host, another port or an https-to-http downgrade, sends nothing more and audits it',
                'ignores every proxy variable: the proxy option is empty and NO_PROXY is *',
                'does not follow a redirect inside curl and ignores proxy variables set in the environment (httpoxy)',
            ],
        ]],
        'fail closed on missing context' => ['tests' => [
            'Database/RowLevelSecurityTest.php' => ['returns zero rows from every tenant table when no context is set'],
            'Database/AuditTest.php' => ['refuses to run outside a Workspace transaction and stores nothing'],
            'Database/WorkspaceJobTest.php' => ['fails hard and logs the security event when a job holds an ID from another Workspace'],
        ]],
    ];
}

/** @return list<string> the paths `tests/Pest.php` puts in the `security` group */
function securityGroupPaths(): array
{
    $pest = (string) file_get_contents(dirname(__DIR__).'/Pest.php');

    if (preg_match('/group\(\s*[\'"]security[\'"]\s*\)\s*->in\(([^;]*)\)\s*;/s', $pest, $match) !== 1) {
        throw new RuntimeException("tests/Pest.php has no `pest()->group('security')->in(...)` registration this manifest can parse: keep it as one call listing quoted paths.");
    }

    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $paths);

    if ($paths[1] === []) {
        throw new RuntimeException('The security group registration in tests/Pest.php lists no quoted paths.');
    }

    return $paths[1];
}

it('lists every guarantee the epic requires', function () {
    expect(array_keys(securityManifest()))->toContain(
        'cross-tenant RLS leak, per tenant table',
        'CSRF on sign-in',
        'CSRF on area change',
        'CSRF on Workspace switch',
        'session rotation on sign-in',
        'session rotation on Workspace switch',
        'session rotation on password reset',
        'invitation single use',
        'invitation permission cap',
        'last users.manage holder',
        'signed job tamper',
        'fail closed on missing context',
    );
});

it('maps every guarantee to tests that exist, by full title, and are neither skipped nor todo', function () {
    $problems = [];

    foreach (securityManifest() as $guarantee => $entry) {
        if (isset($entry['not_applicable'])) {
            continue;
        }

        expect($entry['tests'] ?? [])->not->toBe([], "{$guarantee} lists no test");

        foreach ($entry['tests'] as $file => $titles) {
            $path = dirname(__DIR__).'/'.$file;

            if (! is_file($path)) {
                $problems[] = "{$guarantee}: tests/{$file} does not exist";

                continue;
            }

            $definitions = PestTitles::in($path);

            foreach ($titles as $title) {
                $matches = array_filter($definitions, fn (array $definition): bool => $definition['title'] === $title);

                if ($matches === []) {
                    $problems[] = "{$guarantee}: no test titled '{$title}' in tests/{$file}";

                    continue;
                }

                $live = array_filter($matches, fn (array $definition): bool => array_intersect($definition['chain'], ['skip', 'todo', 'skiponci', 'skipon', 'skipelse']) === []);

                if ($live === []) {
                    $problems[] = "{$guarantee}: '{$title}' in tests/{$file} is skipped or todo";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('keeps every listed test file in the security group', function () {
    $paths = securityGroupPaths();
    $ungrouped = [];

    foreach (securityManifest() as $entry) {
        foreach (array_keys($entry['tests'] ?? []) as $file) {
            $grouped = array_filter($paths, fn (string $path): bool => $file === $path || str_starts_with($file, rtrim($path, '/').'/'));

            if ($grouped === []) {
                $ungrouped[$file] = $file;
            }
        }
    }

    expect(array_values($ungrouped))->toBe([], 'add the file to the security group in tests/Pest.php');
});

it('reads titles from the first string argument across lines, ignoring comments and strings holding code', function () {
    $file = tempnam(sys_get_temp_dir(), 'pest');
    file_put_contents($file, <<<'PHP'
        <?php
        // it('commented out', function () {});
        $text = "it('inside a string', fn () => 1)";
        it(
            'multi-line title',
            function () {
                expect(1)->toBe(1);
            }
        )->skip();
        test('plain title', function () { $this->helper->it('method call'); });
        it('with apostrophe\'s', fn () => 1)->with([1, 2])->group('x');
        PHP);

    $found = PestTitles::in($file);
    unlink($file);

    expect(array_column($found, 'title'))->toBe(['multi-line title', 'plain title', "with apostrophe's"])
        ->and($found[0]['chain'])->toBe(['skip'])
        ->and($found[2]['chain'])->toBe(['with', 'group']);
});

it('parses the security group registration in tests/Pest.php', function () {
    expect(fn () => securityGroupPaths())->not->toThrow(RuntimeException::class);
});

it('records area change as not applicable until an area-switch route exists', function () {
    expect(securityManifest()['CSRF on area change']['not_applicable'])->toBe('not applicable until an area-switch route exists');
});
