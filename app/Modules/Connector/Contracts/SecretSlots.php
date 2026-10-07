<?php

namespace App\Modules\Connector\Contracts;

/** The secret slots a Data Source can hold: one set per authentication type, and `header:{name}` for a secret default header. */
final class SecretSlots
{
    public const API_KEY = 'api_key';

    public const BEARER = 'bearer_token';

    public const BASIC_USERNAME = 'basic_username';

    public const BASIC_PASSWORD = 'basic_password';

    public const HEADER_PREFIX = 'header:';

    /** @return list<string> the slots an authentication type needs */
    public static function forAuth(string $authType): array
    {
        return match ($authType) {
            'api_key' => [self::API_KEY],
            'bearer' => [self::BEARER],
            'basic' => [self::BASIC_USERNAME, self::BASIC_PASSWORD],
            default => [],
        };
    }

    /**
     * The slots a form uses (its auth type's, and one per secret default header), each with the form field that holds it.
     *
     * @param  list<array{name: string, value?: string, secret?: true}>  $headers
     * @return array<string, string> slot => field
     */
    public static function used(string $authType, array $headers): array
    {
        $used = [];

        foreach (self::forAuth($authType) as $slot) {
            $used[$slot] = "secrets.{$slot}";
        }

        foreach ($headers as $i => $header) {
            if (($header['secret'] ?? false) === true) {
                $used[self::header($header['name'])] = "headers.{$i}.value";
            }
        }

        return $used;
    }

    public static function header(string $name): string
    {
        return self::HEADER_PREFIX.strtolower($name);
    }

    public static function isHeader(string $slot): bool
    {
        return str_starts_with($slot, self::HEADER_PREFIX);
    }

    /** The slot as a short audit enum: a header slot is `header`. */
    public static function kind(string $slot): string
    {
        return self::isHeader($slot) ? 'header' : $slot;
    }
}
