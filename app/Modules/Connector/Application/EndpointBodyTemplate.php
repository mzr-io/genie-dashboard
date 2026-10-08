<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\InvalidEndpointBody;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonObject;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;

/**
 * The typed JSON body template of a POST Endpoint (Story 2.9). It is parsed with {@see LosslessJson}, so a number keeps the
 * lexeme it was written with. A parameter may appear only as a whole JSON value, written `{"$param": "name"}`: never inside a
 * string (interpolation) and never as a key, so a bound value can only fill a typed position and can never change the
 * shape of the JSON. Every referenced name must be a declared parameter.
 *
 * Stored tree: the same JSON, with each number as `{"$number": "<lexeme>"}` (jsonb would otherwise rewrite `1.10` and `1e2`).
 * {@see self::text()} turns a stored tree back into canonical JSON text.
 */
final class EndpointBodyTemplate
{
    public const MAX_BYTES = 65536;

    public const MAX_DEPTH = 16;

    /** `{{`, `${` and `$param` written inside a string or a key. A declared `{name}` is refused too (see {@see self::literal()}). */
    private const INTERPOLATION = '/\{\{|\$\{|\$param/';

    /**
     * @param  list<string>  $declared  the declared parameter names
     * @return array{0: mixed, 1: list<string>} the tree to store and the parameter names it references
     *
     * @throws InvalidEndpointBody
     */
    public static function parse(string $text, array $declared): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new InvalidEndpointBody('body-template-too-long', 'The body template can have at most '.self::MAX_BYTES.' bytes.');
        }

        try {
            $value = LosslessJson::decode($text, self::MAX_DEPTH);
        } catch (JsonDepthExceeded) {
            throw new InvalidEndpointBody('body-template-too-deep', 'The body template can nest at most '.self::MAX_DEPTH.' levels.');
        } catch (JsonParseFailed) {
            throw new InvalidEndpointBody('body-template-invalid', "The body template isn't valid JSON.");
        }

        // A bare null would be stored as SQL NULL, which means "no template".
        if ($value === null) {
            throw new InvalidEndpointBody('body-template-invalid', "The body template isn't valid JSON.");
        }

        $refs = [];
        $tree = self::walk($value, $declared, $refs);

        return [$tree, array_values(array_unique($refs))];
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $refs
     */
    private static function walk(mixed $value, array $declared, array &$refs): mixed
    {
        if ($value instanceof DecimalLiteral) {
            return ['$number' => $value->lexeme];
        }

        if (is_string($value)) {
            self::literal($value, $declared);

            return $value;
        }

        if ($value instanceof JsonObject) {
            if ($value->has('$param')) {
                $name = $value->get('$param');

                if (count($value) !== 1 || ! is_string($name)) {
                    throw new InvalidEndpointBody('body-template-param-invalid', 'A parameter is written {"$param": "name"}, alone in its object.');
                }

                if (! in_array($name, $declared, true)) {
                    throw new InvalidEndpointBody('body-template-param-unknown', "The body template uses {$name}, which is not a declared parameter.", $name);
                }

                $refs[] = $name;

                return ['$param' => $name];
            }

            $object = new \stdClass;

            foreach ($value->entries() as [$key, $member]) {
                if ($key !== '' && $key[0] === '$') {
                    throw new InvalidEndpointBody('body-template-interpolation', 'A key cannot start with $ or hold a parameter. A parameter replaces a whole value only.');
                }

                self::literal($key, $declared);
                $object->{$key} = self::walk($member, $declared, $refs);
            }

            return $object;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::walk($item, $declared, $refs), $value);
        }

        return $value;
    }

    /** @param  list<string>  $declared */
    private static function literal(string $text, array $declared): void
    {
        // jsonb cannot hold U+0000.
        if (str_contains($text, "\0")) {
            throw new InvalidEndpointBody('body-template-invalid', "The body template can't contain the null character.");
        }

        $named = false;

        foreach ($declared as $name) {
            $named = $named || str_contains($text, '{'.$name.'}');
        }

        if ($named || preg_match(self::INTERPOLATION, $text) === 1) {
            throw new InvalidEndpointBody('body-template-interpolation', 'A parameter cannot be written inside text or a key. Use {"$param": "name"} as a whole value.');
        }
    }

    /** The canonical JSON text of a stored tree (as decoded without associative arrays), numbers as written. */
    public static function text(mixed $stored): string
    {
        return LosslessJson::canonicalOf(self::lift($stored));
    }

    private static function lift(mixed $stored): mixed
    {
        if ($stored instanceof \stdClass) {
            $members = get_object_vars($stored);

            if (array_keys($members) === ['$number'] && is_string($members['$number'])) {
                return new DecimalLiteral($members['$number']);
            }

            return new JsonObject(array_map(self::lift(...), $members));
        }

        if (is_array($stored)) {
            return array_map(self::lift(...), $stored);
        }

        return $stored;
    }
}
