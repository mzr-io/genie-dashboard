<?php

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\UserMembership;
use App\Modules\Identity\Application\AvatarLimit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

// Story 1.18: Profile & settings on the SQLite Feature suite. The same flows run against PostgreSQL in the
// Database suite (tests/Database/ProfileSettingsTest.php).
beforeEach(function () {
    $this->withoutVite();
    Storage::fake('avatars');
});

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
const WEBP = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

function image(string $name, string $base64): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode($base64));
}

function profilePayload(array $override = []): array
{
    return $override + ['name' => 'Ada Lovelace', 'locale' => 'en', 'timezone' => 'Europe/London', 'keyboard_shortcuts' => '1'];
}

function saveProfile(User $user, array $payload)
{
    return test()->actingAs($user)->from(route('profile.edit'))->post(route('profile.update'), $payload + ['_method' => 'patch']);
}

it('shows name, avatar, locale, time zone, Change password and the shortcuts switch', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Profile')
        ->where('locales', ['en'])
        ->has('timezones')
        ->where('auth.user.keyboard_shortcuts', true)
        ->where('auth.user.avatar', null)
        ->missing('auth.user.avatar_path')
        ->has('passwordRules'));
});

it('lists every PHP time zone identifier', function () {
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('timezones', DateTimeZone::listIdentifiers()));
});

it('saves name, locale, time zone and the shortcuts switch, and leaves the email alone', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);

    saveProfile($user, profilePayload(['keyboard_shortcuts' => '0', 'email' => 'other@example.test']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->locale)->toBe('en')
        ->and($user->timezone)->toBe('Europe/London')
        ->and($user->keyboard_shortcuts)->toBeFalse()
        ->and($user->email)->toBe('ada@example.test');

    $this->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.keyboard_shortcuts', false)
        ->where('auth.user.timezone', 'Europe/London'));
});

it('defaults the shortcuts switch to On', function () {
    $user = User::factory()->create();

    expect($user->refresh()->keyboard_shortcuts)->toBeTrue();
});

it('refuses an unsupported locale, an unknown time zone and a missing name, and changes nothing', function (array $override, string $field) {
    $user = User::factory()->create(['name' => 'Before']);

    saveProfile($user, profilePayload($override))->assertSessionHasErrors($field);

    expect($user->refresh()->name)->toBe('Before')->and($user->locale)->toBeNull();
})->with([
    'locale' => [['locale' => 'fr'], 'locale'],
    'time zone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'name' => [['name' => ''], 'name'],
    'switch' => [['keyboard_shortcuts' => 'maybe'], 'keyboard_shortcuts'],
]);

it('stores a PNG, JPEG or WebP avatar under a random name on the private disk and shows it', function (string $name, string $data, string $extension) {
    $user = User::factory()->create();

    saveProfile($user, profilePayload(['avatar' => image($name, $data)]))->assertSessionHasNoErrors();

    $path = $user->refresh()->avatar_path;
    expect($path)->toMatch('/^[a-z0-9]{40}\.'.$extension.'$/');
    Storage::disk('avatars')->assertExists($path);
    expect(Storage::disk('avatars')->path($path))->not->toContain(public_path());

    $this->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.avatar', fn ($url) => str_starts_with($url, "/avatars/{$user->id}?v=")));
})->with([
    'png' => ['me.png', PNG, 'png'],
    'jpeg' => ['me.jpeg', JPEG, 'jpg'],
    'webp' => ['me.webp', WEBP, 'webp'],
]);

it('replaces the avatar and deletes the old file', function () {
    $user = User::factory()->create();
    saveProfile($user, profilePayload(['avatar' => image('a.png', PNG)]));
    $old = $user->refresh()->avatar_path;

    saveProfile($user, profilePayload(['avatar' => image('b.webp', WEBP)]))->assertSessionHasNoErrors();

    $new = $user->refresh()->avatar_path;
    expect($new)->not->toBe($old);
    Storage::disk('avatars')->assertMissing($old);
    Storage::disk('avatars')->assertExists($new);
});

it('keeps the avatar when a save has none', function () {
    $user = User::factory()->create();
    saveProfile($user, profilePayload(['avatar' => image('a.png', PNG)]));
    $path = $user->refresh()->avatar_path;

    saveProfile($user, profilePayload(['name' => 'Renamed']))->assertSessionHasNoErrors();

    expect($user->refresh()->avatar_path)->toBe($path)->and($user->name)->toBe('Renamed');
    Storage::disk('avatars')->assertExists($path);
});

it('refuses SVG, a wrong type and a disguised file with a field error, and the existing avatar stays', function (UploadedFile $file) {
    $user = User::factory()->create();
    saveProfile($user, profilePayload(['avatar' => image('a.png', PNG)]));
    $path = $user->refresh()->avatar_path;

    saveProfile($user, profilePayload(['name' => 'Changed', 'avatar' => $file]))->assertSessionHasErrors('avatar');

    expect($user->refresh()->avatar_path)->toBe($path)->and($user->name)->not->toBe('Changed');
    expect(Storage::disk('avatars')->allFiles())->toBe([$path]);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'svg named png' => fn () => UploadedFile::fake()->createWithContent('a.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
    'gif' => fn () => UploadedFile::fake()->createWithContent('a.gif', base64_decode('R0lGODlhAQABAAAAACw=')),
    'text named png' => fn () => UploadedFile::fake()->createWithContent('a.png', 'not an image'),
    'png named txt' => fn () => image('a.txt', PNG),
    'pdf' => fn () => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
]);

it('refuses an avatar over the tunable size and accepts one at it', function () {
    $size = strlen(base64_decode(PNG));
    config(['dashflow.tunables.profile.avatar_max_bytes.value' => (string) ($size - 1)]);
    $user = User::factory()->create();

    saveProfile($user, profilePayload(['avatar' => image('a.png', PNG)]))->assertSessionHasErrors('avatar');
    expect($user->refresh()->avatar_path)->toBeNull();

    config(['dashflow.tunables.profile.avatar_max_bytes.value' => (string) $size]);
    saveProfile($user, profilePayload(['avatar' => image('a.png', PNG)]))->assertSessionHasNoErrors();
    expect($user->refresh()->avatar_path)->not->toBeNull();
});

it('falls back to PHP upload_max_filesize while the tunable is unset or unusable', function (mixed $value) {
    config(['dashflow.tunables.profile.avatar_max_bytes.value' => $value]);

    expect(AvatarLimit::bytes())->toBe(AvatarLimit::iniBytes((string) ini_get('upload_max_filesize')));
})->with([null, '', '0', '-5', 'big']);

it('treats an unlimited, zero or unreadable PHP limit as no application limit, never 0 bytes refused', function (string $value, int $bytes) {
    expect(AvatarLimit::iniBytes($value))->toBe($bytes);
})->with([['-1', 0], ['0', 0], ['', 0], ['junk', 0], ['1.5M', 1572864], ['2G', 2147483648], ['0.5K', 512], ['99999999999999999999G', PHP_INT_MAX]]);

it('has no application limit when PHP is unlimited, and the client gets null', function () {
    config(['dashflow.tunables.profile.avatar_max_bytes.value' => null]);

    expect(AvatarLimit::bytes('-1'))->toBe(0)->and(AvatarLimit::bytes('0'))->toBe(0)->and(AvatarLimit::bytes('2M'))->toBe(2097152);

    // A configured tunable always wins, and an unusable one falls back to the ini value.
    config(['dashflow.tunables.profile.avatar_max_bytes.value' => '500']);
    expect(AvatarLimit::bytes('-1'))->toBe(500);
});

it('sends the real limit to the client', function () {
    config(['dashflow.tunables.profile.avatar_max_bytes.value' => '1234']);

    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('avatarMaxBytes', 1234));
});

it('refuses a small file that declares a huge canvas, with a field error', function (int $width, int $height) {
    // A valid PNG signature and header only: a few dozen bytes that claim a large picture.
    $header = pack('N', $width).pack('N', $height)."\x08\x06\x00\x00\x00";
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header));
    $user = User::factory()->create();

    saveProfile($user, profilePayload(['avatar' => UploadedFile::fake()->createWithContent('big.png', $png)]))->assertSessionHasErrors('avatar');

    expect($user->refresh()->avatar_path)->toBeNull();
})->with([
    'too wide' => [5000, 10],
    'too tall' => [10, 4097],
    'over 16 megapixels' => [4000, 4000 + 1],
]);

it('maps a PHP upload size error to the too-large message and any other upload error to a generic one', function (int $code, string $fragment) {
    config(['dashflow.tunables.profile.avatar_max_bytes.value' => '1000']);
    $user = User::factory()->create();
    // A failed upload has no temporary file at all.
    $file = new UploadedFile('', 'a.png', 'image/png', $code, true);

    $response = saveProfile($user, profilePayload(['avatar' => $file]));

    $response->assertSessionHasErrors('avatar');
    expect(session('errors')->first('avatar'))->toContain($fragment);
    expect($user->refresh()->avatar_path)->toBeNull();
})->with([
    'ini size' => [UPLOAD_ERR_INI_SIZE, 'no larger than'],
    'form size' => [UPLOAD_ERR_FORM_SIZE, 'no larger than'],
    'partial' => [UPLOAD_ERR_PARTIAL, 'upload failed'],
    'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR, 'upload failed'],
    'cant write' => [UPLOAD_ERR_CANT_WRITE, 'upload failed'],
]);

it('answers 413 before the app runs when the body is over post_max_size (the page then shows the too-large message on avatar)', function () {
    $this->actingAs(User::factory()->create())
        ->withServerVariables(['CONTENT_LENGTH' => '99999999999'])
        ->post(route('profile.update'), ['_method' => 'patch'])
        ->assertStatus(413);
});

it('reads PHP shorthand sizes', function (string $value, int $bytes) {
    expect(AvatarLimit::iniBytes($value))->toBe($bytes);
})->with([['2M', 2097152], ['512K', 524288], ['1G', 1073741824], ['100', 100], ['2MB', 2097152], ['abc', 0], ['', 0]]);

/**
 * @param  array<int, list<array{0: string, 1?: string, 2?: string}>>  $byUser  user ID => [workspace, membership status, workspace status]
 */
function fakeMemberships(array $byUser): void
{
    app()->instance(MembershipLookup::class, new class($byUser) implements MembershipLookup
    {
        /** @param array<int, list<array{0: string, 1?: string, 2?: string}>> $byUser */
        public function __construct(private array $byUser) {}

        public function forUser(int $userId): array
        {
            return array_map(fn (array $m): UserMembership => new UserMembership(
                'm-'.$m[0].'-'.$userId, $m[0], 'W', null, $m[2] ?? 'active', 'user', $m[1] ?? 'active', null,
            ), $this->byUser[$userId] ?? []);
        }
    });
}

function avatarOwner(): User
{
    $owner = User::factory()->create();
    saveProfile($owner, profilePayload(['avatar' => image('a.webp', WEBP)]));

    return $owner->refresh();
}

it('serves the owner their own picture with a fixed type, nosniff, a CSP and a private cache', function () {
    $owner = avatarOwner();
    fakeMemberships([]);

    $response = $this->actingAs($owner)->get($owner->avatar);

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=86400')->not->toContain('no-store')
        ->and($response->getContent())->toBe(base64_decode(WEBP));
});

it('serves a person who shares an active Workspace, with the same headers and no caching', function () {
    $owner = avatarOwner();
    $viewer = User::factory()->create();
    fakeMemberships([$owner->id => [['w1'], ['w9']], $viewer->id => [['w1'], ['w2']]]);

    $response = $this->actingAs($viewer)->get($owner->avatar);

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('answers 404, never 403, to a person in a different Workspace, one with only inactive memberships or none', function (array $viewerMemberships) {
    $owner = avatarOwner();
    $viewer = User::factory()->create();
    fakeMemberships([$owner->id => [['w1']], $viewer->id => $viewerMemberships]);

    $this->actingAs($viewer)->get($owner->avatar)->assertNotFound();
})->with([
    'different workspace' => [[['w2']]],
    'suspended membership' => [[['w1', 'suspended']]],
    'inactive workspace' => [[['w1', 'active', 'inactive']]],
    'none' => [[]],
]);

it('answers 404 when the owner\'s own membership in the shared Workspace is not active', function () {
    $owner = avatarOwner();
    $viewer = User::factory()->create();
    fakeMemberships([$owner->id => [['w1', 'suspended']], $viewer->id => [['w1']]]);

    $this->actingAs($viewer)->get($owner->avatar)->assertNotFound();
});

it('answers 404 when the membership lookup fails', function () {
    $owner = avatarOwner();
    $viewer = User::factory()->create();
    app()->instance(MembershipLookup::class, new class implements MembershipLookup
    {
        public function forUser(int $userId): array
        {
            throw new RuntimeException('down');
        }
    });

    $this->actingAs($viewer)->get($owner->avatar)->assertNotFound();
});

it('redirects a guest to sign-in', function () {
    $owner = avatarOwner();
    $url = $owner->avatar;
    auth()->logout();

    $this->get($url)->assertRedirect(route('login'));
});

it('answers 404 for a user without an avatar, a missing file and an unknown user', function () {
    $bare = User::factory()->create();
    $gone = User::factory()->create(['avatar_path' => str_repeat('a', 40).'.png']);

    $this->actingAs($bare)->get("/avatars/{$bare->id}")->assertNotFound();
    $this->actingAs($gone)->get("/avatars/{$gone->id}")->assertNotFound();
    $this->actingAs($bare)->get('/avatars/999999')->assertNotFound();
});

it('never reads a path outside the avatar store, whatever is stored', function () {
    $evil = User::factory()->create(['avatar_path' => '../../../../etc/passwd']);

    $this->actingAs($evil)->get("/avatars/{$evil->id}")->assertNotFound();
});

it('forces the stored type even when the stored extension and bytes disagree', function () {
    $owner = User::factory()->create();
    Storage::disk('avatars')->put($name = str_repeat('b', 40).'.png', '<html><script>alert(1)</script></html>');
    $owner->forceFill(['avatar_path' => $name])->save();

    $this->actingAs($owner)->get($owner->avatar)->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('has no avatar route that is not behind sign-in', function () {
    $route = app('router')->getRoutes()->getByName('avatars.show');

    expect($route->gatherMiddleware())->toContain('auth');
});
