<?php

namespace App\Modules\Mapping\Contracts;

/**
 * Text Templates of a Draft (Story 5.4): `config.templates` is `{<slot key>: {source, parts}}`, server-owned like the mapping keys, and a slot with one
 * is read from the stored path `tpl:<slot key>`. The plan carries it as its `templates` key.
 *
 * The wire form of a rendered template (it is a text slot's value, cached for every viewer, so it holds nothing of the viewer): literal text with `{{`
 * and `}}` for braces, `{-}` for a value that is null or missing (the Slot's empty display), `{#<number>|<format>}` for a number, `{@<date>|<pattern>}`
 * for a date and `{user.<key>}` for the viewer. The browser formats numbers and dates in the viewer's locale and fills the viewer's own values.
 */
final class Templates
{
    public const KEY = 'templates';

    /** The prefix of the stored path a slot with a template is read by. */
    public const PREFIX = 'tpl:';

    /** The empty marker of the wire form. */
    public const EMPTY = '{-}';

    /** Braces in data or literal text are doubled, so a value can never be read as a token. */
    public static function escape(string $text): string
    {
        return str_replace(['{', '}'], ['{{', '}}'], $text);
    }
}
