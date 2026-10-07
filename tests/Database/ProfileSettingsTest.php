<?php

use App\Models\User;
use App\Platform\Tenancy\WorkspaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 1.18 against the real PostgreSQL: role `app` may update the new `users` columns and delete the other
// `sessions`; the password change is recorded as a security event in the Workspace; `workspace_settings`
// is a tenant table under forced row-level security, read through the kernel for the active Workspace only.
beforeEach(function () {
    $this->withoutVite();
    Storage::fake('avatars');
});

const PROFILE_PASSWORD = 'a-long-enough-password';
const PROFILE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** @return array{0: int, 1: string} the user ID and the membership ID */
function profileMember(string $workspaceId, string $email, string $role = 'user'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(PROFILE_PASSWORD), $user]);

    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, 'active']);

    return [$user, $membership];
}

function profileSignIn(string $email): void
{
    test()->postJson('/login', ['email' => $email, 'password' => PROFILE_PASSWORD, 'role' => 'user'])->assertOk();
}

function profileSession(string $id, int $userId): void
{
    Cluster::superuser()->prepare('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)')->execute([$id, $userId, 'x', time()]);
}

it('saves the profile as role app: the new users columns, a stored avatar and the shortcuts switch', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = profileMember($workspace, 'ada@example.test');
    profileSignIn('ada@example.test');

    $this->from(route('profile.edit'))->post(route('profile.update'), [
        '_method' => 'patch', 'name' => 'Ada L', 'locale' => 'en', 'timezone' => 'Asia/Dhaka', 'keyboard_shortcuts' => '0',
        'avatar' => UploadedFile::fake()->createWithContent('me.png', base64_decode(PROFILE_PNG)),
    ])->assertSessionHasNoErrors();

    $row = Cluster::rows(Cluster::superuser(), 'select name, locale, timezone, avatar_path, keyboard_shortcuts from users where id = ?', [$user])[0];
    expect($row['name'])->toBe('Ada L')
        ->and($row['locale'])->toBe('en')
        ->and($row['timezone'])->toBe('Asia/Dhaka')
        ->and($row['keyboard_shortcuts'])->toBeFalse()
        ->and($row['avatar_path'])->toMatch('/^[a-z0-9]{40}\.png$/');
    Storage::disk('avatars')->assertExists($row['avatar_path']);

    $this->get("/avatars/{$user}")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('defaults keyboard_shortcuts to On for a user created without it', function () {
    $user = Cluster::user('new@example.test');

    expect(Cluster::rows(Cluster::superuser(), 'select keyboard_shortcuts from users where id = ?', [$user])[0]['keyboard_shortcuts'])->toBeTrue();
});

it('changes the password, deletes the other sessions as role app, stays signed in and records identity.password.changed in the Workspace', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = profileMember($workspace, 'ada@example.test');
    [$other] = profileMember($workspace, 'bob@example.test');
    profileSignIn('ada@example.test');
    profileSession('ada-laptop', $user);
    profileSession('ada-phone', $user);
    profileSession('bob-laptop', $other);

    $this->from(route('profile.edit'))->put(route('user-password.update'), [
        'current_password' => PROFILE_PASSWORD, 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    $hash = Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'];
    expect(Hash::check('a-brand-new-password', $hash))->toBeTrue()
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select id from sessions order by id'), 'id'))->toBe(['bob-laptop']);

    $this->assertAuthenticated();
    $this->get(route('profile.edit'))->assertOk();

    $events = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'identity.password.changed'");
    expect($events)->toHaveCount(1)
        ->and($events[0]['workspace_id'])->toBe($workspace)
        ->and($events[0]['security'])->toBeTrue()
        ->and($events[0]['actor'])->toBe($membership)
        ->and(json_decode($events[0]['after_state'], true))->toBe(['user_id' => $user, 'membership_id' => $membership]);
    // Neither password appears anywhere in the audit log.
    expect(json_encode(Cluster::rows(Cluster::superuser(), 'select * from audit_events')))->not->toContain('brand-new')->not->toContain(PROFILE_PASSWORD);
});

it('changes nothing and records nothing for a wrong current password', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = profileMember($workspace, 'ada@example.test');
    profileSignIn('ada@example.test');
    profileSession('ada-laptop', $user);
    $before = Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'];

    $this->from(route('profile.edit'))->put(route('user-password.update'), [
        'current_password' => 'not-it', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasErrors('current_password');

    expect(Cluster::rows(Cluster::superuser(), 'select password from users where id = ?', [$user])[0]['password'])->toBe($before)
        ->and(Cluster::rows(Cluster::superuser(), "select id from sessions where id = 'ada-laptop'"))->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), "select id from audit_events where action = 'identity.password.changed'"))->toBe([]);
});

it('lists the active Workspace\'s help links and never another Workspace\'s', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    Cluster::seedSettings($acme, json_encode([['label' => 'Acme guide', 'url' => 'https://acme.example.test/guide']]), 'https://acme.example.test/contact');
    Cluster::seedSettings($other, json_encode([['label' => 'Other guide', 'url' => 'https://other.example.test/guide']]), 'https://other.example.test/contact');
    profileMember($acme, 'ada@example.test');
    profileMember($other, 'zed@example.test');

    profileSignIn('ada@example.test');
    $this->get(route('help'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Help')
        ->where('helpLinks', [['label' => 'Acme guide', 'url' => 'https://acme.example.test/guide']])
        ->where('contactHref', 'https://acme.example.test/contact'));
});

it('shows an empty list and plain-text contact for a Workspace without a settings row', function () {
    $workspace = Cluster::workspace('Acme');
    profileMember($workspace, 'ada@example.test');
    profileSignIn('ada@example.test');

    $this->get(route('help'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('helpLinks', [])->where('contactHref', null));
});

it('reads no settings row without a Workspace context, and only its own with one', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedSettings($a, '[{"label":"A","url":"https://a.example.test"}]');
    Cluster::seedSettings($b, '[{"label":"B","url":"https://b.example.test"}]');

    $app = Cluster::directApp();
    expect(Cluster::rows($app, 'select id from workspace_settings'))->toBe([]);

    $seen = Cluster::inWorkspace($app, $a, fn ($pdo) => array_column(Cluster::rows($pdo, 'select workspace_id from workspace_settings'), 'workspace_id'));
    expect($seen)->toBe([$a]);

    // The kernel reader runs in the request transaction; with none there is no row.
    expect(app(WorkspaceSettings::class)->helpLinks())->toBe([]);
});

it('keeps one settings row per Workspace and a JSON array of links', function () {
    $a = Cluster::workspace('A');
    Cluster::seedSettings($a);

    expect(fn () => Cluster::seedSettings($a))->toThrow(PDOException::class);
    expect(fn () => Cluster::seedSettings(Cluster::workspace('B'), '"not-a-list"'))->toThrow(PDOException::class);

    $row = Cluster::rows(Cluster::superuser(), 'select revision, contact_href from workspace_settings where workspace_id = ?', [$a])[0];
    expect($row['revision'])->toBe(1)->and($row['contact_href'])->toBeNull();
});

it('refuses the Workspace app role a write to another Workspace\'s settings', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedSettings($b);

    expect(fn () => Cluster::inWorkspace(Cluster::directApp(), $a, fn ($pdo) => Cluster::rows($pdo, 'insert into workspace_settings (id, workspace_id, created_at, updated_at) values (?, ?, now(), now())', [(string) Str::uuid7(), $b])))
        ->toThrow(PDOException::class);

    $changed = Cluster::inWorkspace(Cluster::directApp(), $a, function ($pdo) {
        $statement = $pdo->prepare("update workspace_settings set contact_href = 'https://evil.example.test'");
        $statement->execute();

        return $statement->rowCount();
    });
    expect($changed)->toBe(0);
});
