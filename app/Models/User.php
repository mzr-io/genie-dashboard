<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $locale
 * @property string|null $timezone
 * @property string|null $avatar_path
 * @property bool $keyboard_shortcuts
 * @property-read string|null $avatar
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale', 'timezone', 'avatar_path', 'keyboard_shortcuts'])]
#[Hidden(['password', 'remember_token', 'avatar_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    protected $appends = ['avatar'];

    /** @var array<string, mixed> */
    protected $attributes = ['keyboard_shortcuts' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'keyboard_shortcuts' => 'boolean',
        ];
    }

    /**
     * The profile picture's address on the authenticated avatar route (null without one). The query
     * string changes with every new file, so a replaced picture is never served from a stale cache.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path === null || $this->avatar_path === ''
            ? null
            : route('avatars.show', ['user' => $this->id, 'v' => substr(sha1($this->avatar_path), 0, 12)], false));
    }
}
