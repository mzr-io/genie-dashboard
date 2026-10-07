<?php

use App\Models\User;
use App\Modules\Identity\Application\UpdateProfile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Story 1.18: the failure paths of the profile save service.
const UP_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function upData(): array
{
    return ['name' => 'New', 'locale' => 'en', 'timezone' => 'UTC', 'keyboard_shortcuts' => true];
}

function upFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('a.png', base64_decode(UP_PNG));
}

it('removes the newly stored file and leaves avatar_path unchanged when the row save throws', function () {
    Storage::fake('avatars');
    Storage::disk('avatars')->put($old = str_repeat('o', 40).'.png', 'old');
    $user = User::factory()->create(['avatar_path' => $old, 'name' => 'Before']);
    User::saving(fn () => throw new RuntimeException('db down'));

    expect(fn () => app(UpdateProfile::class)->handle($user, upData(), upFile()))->toThrow(RuntimeException::class);

    expect($user->fresh()->avatar_path)->toBe($old)->and($user->fresh()->name)->toBe('Before')
        ->and(Storage::disk('avatars')->allFiles())->toBe([$old]);
});

it('still succeeds with the new path and logs identity.avatar.delete_failed when deleting the old file fails', function () {
    Log::spy();
    Storage::fake('avatars');
    $old = str_repeat('o', 40).'.png';
    $stored = [];
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturnUsing(function ($name) use (&$stored) {
        $stored[] = $name;

        return true;
    });
    $disk->shouldReceive('delete')->with($old)->andThrow(new RuntimeException('read-only'));
    Storage::set('avatars', $disk);
    $user = User::factory()->create(['avatar_path' => $old]);

    app(UpdateProfile::class)->handle($user, upData(), upFile());

    expect($user->fresh()->avatar_path)->toBe($stored[0])->and($user->fresh()->name)->toBe('New');
    Log::shouldHaveReceived('warning')->with('identity.avatar.delete_failed')->once();
});
