<?php

use App\Models\User;
use App\Platform\Tenancy\WorkspaceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

// Story 1.18: Help & support. Row-level security (another Workspace's row is unreadable) runs in the
// Database suite; here the single settings row is read and sanitised.
beforeEach(fn () => $this->withoutVite());

function settingsRow(array $links, ?string $contact = null): void
{
    $workspace = (string) Str::uuid7();
    DB::table('workspaces')->insert(['id' => $workspace, 'name' => 'Acme', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspace_settings')->insert([
        'id' => (string) Str::uuid7(), 'workspace_id' => $workspace, 'help_links' => json_encode($links),
        'contact_href' => $contact, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('shows a guest the page with no links and no contact address', function () {
    settingsRow([['label' => 'Docs', 'url' => 'https://docs.example.test']], 'https://help.example.test');

    $this->get(route('help'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Help')
        ->where('helpLinks', [])
        ->where('contactHref', null)
        ->where('shell', null));
});

it('lists the configured links and the contact address for a signed-in person', function () {
    settingsRow([
        ['label' => 'Guide', 'url' => 'https://docs.example.test/start?a=1&b=2'],
        ['label' => 'Status', 'url' => 'http://status.example.test'],
    ], 'https://help.example.test/contact');

    $this->actingAs(User::factory()->create())->get(route('help'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Help')
        ->where('helpLinks', [
            ['label' => 'Guide', 'url' => 'https://docs.example.test/start?a=1&b=2'],
            ['label' => 'Status', 'url' => 'http://status.example.test'],
        ])
        ->where('contactHref', 'https://help.example.test/contact'));
});

it('shows an empty list and no contact link when nothing is configured', function () {
    $this->actingAs(User::factory()->create())->get(route('help'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('helpLinks', [])
        ->where('contactHref', null));
});

it('drops anything that is not an http or https link, and keeps labels as plain text', function () {
    settingsRow([
        ['label' => 'Script', 'url' => 'javascript:alert(1)'],
        ['label' => 'Data', 'url' => 'data:text/html,<script>1</script>'],
        ['label' => 'Relative', 'url' => '/internal'],
        ['label' => 'Spaces', 'url' => 'https://example.test/a b'],
        ['label' => '  ', 'url' => 'https://blank.example.test'],
        ['label' => 'No url'],
        'not-an-array',
        ['label' => '<b>Bold</b> & co', 'url' => 'https://ok.example.test'],
    ], 'javascript:alert(1)');

    $this->actingAs(User::factory()->create())->get(route('help'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('helpLinks', [['label' => '<b>Bold</b> & co', 'url' => 'https://ok.example.test']])
        ->where('contactHref', null));
});

it('accepts a mailto contact address and nothing else besides web links', function (string $value, ?string $expected) {
    settingsRow([], $value);

    expect(app(WorkspaceSettings::class)->contactHref())->toBe($expected);
})->with([
    ['mailto:admin@example.test', 'mailto:admin@example.test'],
    ['https://example.test/help', 'https://example.test/help'],
    ['mailto:', null],
    ['tel:+123', null],
    ['ftp://example.test', null],
    ['   ', null],
]);

it('tolerates a stored value that is not a list', function () {
    $workspace = (string) Str::uuid7();
    DB::table('workspaces')->insert(['id' => $workspace, 'name' => 'A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspace_settings')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $workspace, 'help_links' => '"oops"', 'created_at' => now(), 'updated_at' => now()]);

    expect(app(WorkspaceSettings::class)->helpLinks())->toBe([]);
});

it('reads the row once for the links and the contact address together', function () {
    settingsRow([['label' => 'Guide', 'url' => 'https://docs.example.test']], 'https://help.example.test');
    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'workspace_settings')) {
            $queries++;
        }
    });

    $settings = app(WorkspaceSettings::class);
    $settings->helpLinks();
    $settings->contactHref();
    $settings->helpLinks();

    expect($queries)->toBe(1);
});

it('logs workspace_settings.read_failed with the exception class and shows the empty list when the read fails', function () {
    Log::spy();
    Schema::drop('workspace_settings');

    $settings = app(WorkspaceSettings::class);

    expect($settings->helpLinks())->toBe([])->and($settings->contactHref())->toBeNull();
    Log::shouldHaveReceived('warning')
        ->with('workspace_settings.read_failed', ['exception' => QueryException::class])->once();
});
