<?php

namespace App\Modules\Identity\Infrastructure;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profile pictures on the private `avatars` disk, under a random name. PNG, JPEG and WebP only; the
 * extension is derived from the detected content type, never from the client's file name, and the same
 * map fixes the `Content-Type` the avatar route sends.
 */
final class AvatarStore
{
    /** Content type => stored extension. */
    public const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** Stored extension => the one Content-Type served. */
    public const CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** Safety bounds on the decoded picture (not a product setting): a small file can still declare a huge canvas. */
    public const MAX_SIDE = 4096;

    public const MAX_PIXELS = 16_000_000;

    private function disk(): Filesystem
    {
        return Storage::disk('avatars');
    }

    /** The detected content type when the file really is a PNG, JPEG or WebP image; otherwise null. */
    public static function detect(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if (! is_array($info)) {
            return null;
        }

        return array_key_exists($info['mime'], self::TYPES) ? $info['mime'] : null;
    }

    /** True when the image's declared width and height are within the safety bounds. */
    public static function withinBounds(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);

        if (! is_array($info)) {
            return false;
        }

        [$width, $height] = $info;

        return $width > 0 && $height > 0
            && $width <= self::MAX_SIDE && $height <= self::MAX_SIDE
            && $width * $height <= self::MAX_PIXELS;
    }

    /** Stores the file and returns its random name. */
    public function put(UploadedFile $file): string
    {
        $type = self::detect($file);

        if ($type === null || ! self::withinBounds($file)) {
            throw new RuntimeException('Not an accepted image.');
        }

        $name = Str::lower(Str::random(40)).'.'.self::TYPES[$type];

        $stream = fopen((string) $file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('The avatar could not be read.');
        }

        try {
            if (! $this->disk()->put($name, $stream)) {
                throw new RuntimeException('The avatar could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $name;
    }

    public function delete(?string $name): void
    {
        if ($name !== null && self::valid($name)) {
            $this->disk()->delete($name);
        }
    }

    /** The file's bytes, or null when the name is not one we issued or the file is gone. */
    public function read(?string $name): ?string
    {
        if ($name === null || ! self::valid($name) || ! $this->disk()->exists($name)) {
            return null;
        }

        $bytes = $this->disk()->get($name);

        return is_string($bytes) ? $bytes : null;
    }

    public static function contentType(string $name): ?string
    {
        return self::CONTENT_TYPES[pathinfo($name, PATHINFO_EXTENSION)] ?? null;
    }

    /** Only names of the shape this store issues ever reach the disk, so a stored value cannot escape it. */
    private static function valid(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9]{40}\.(png|jpg|webp)$/', $name);
    }
}
