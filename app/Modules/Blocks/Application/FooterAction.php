<?php

namespace App\Modules\Blocks\Application;

/**
 * The footer action of a Block (Story 5.4, FR-34, UX-DR-98): `{target, label, url}`. The target is `external` (an http or https link with `{user.*}`
 * tokens, percent-encoded in position by the browser) or `expanded_view` (the Expanded view of Story 5.9, inert until then). The label is literal text
 * with `{user.*}` tokens. The scheme and host of a link are literals: a token there, any other scheme, or credentials in the address are refused with the
 * `url-invalid` message, so a person's value can never pick where the link goes.
 */
final class FooterAction
{
    public const EXTERNAL = 'external';

    public const EXPANDED_VIEW = 'expanded_view';

    public const URL_MAX = 500;

    public const LABEL_MAX = 60;

    private const TOKEN = '/\{user\.[a-z_]+\}/';

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors  appended to, keyed `config.display.footer.{part}`
     * @return array{target: string, label: string, url: string|null}|null null when the Admin has set nothing
     */
    public static function check(array $input, array &$errors): ?array
    {
        $root = 'config.display.footer';
        $target = $input['target'] ?? self::EXTERNAL;
        $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';

        foreach (array_keys($input) as $key) {
            if (! in_array($key, ['target', 'label', 'url'], true)) {
                $errors["{$root}.{$key}"] = ['This setting is not part of the footer action.'];
            }
        }

        if ($label === '' && $url === '') {
            return null;
        }

        if (! in_array($target, [self::EXTERNAL, self::EXPANDED_VIEW], true)) {
            $errors["{$root}.target"] = ['Choose External link or Expanded view.'];

            return null;
        }

        if ($label === '') {
            $errors["{$root}.label"] = ['Enter the label of the link.'];
        } elseif (mb_strlen($label) > self::LABEL_MAX) {
            $errors["{$root}.label"] = ['Use '.self::LABEL_MAX.' characters or fewer.'];
        } else {
            // Literal text with `{user.*}` tokens only; `{{` and `}}` are literal braces.
            $rest = preg_replace(self::TOKEN, '', str_replace(['{{', '}}'], '', $label)) ?? '';

            if (str_contains($rest, '{') || str_contains($rest, '}') || preg_match('/[\p{Cc}\p{Cf}]/u', $label) === 1) {
                $errors["{$root}.label"] = ['A footer label takes literal text and {user.*} tokens only.'];
            }
        }

        if ($target === self::EXPANDED_VIEW) {
            if ($url !== '') {
                $errors["{$root}.url"] = ['The Expanded view has no address to set.'];
            }

            return ['target' => $target, 'label' => $label, 'url' => null];
        }

        if (! self::validUrl($url)) {
            $errors["{$root}.url"] = ['Use a link that starts with https:// or http://.'];
        }

        return ['target' => $target, 'label' => $label, 'url' => $url];
    }

    /** An http or https address with literal scheme and host, `{user.*}` tokens only after them, and no credentials. */
    public static function validUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::URL_MAX || preg_match('/[\s\p{Cc}\p{Cf}\\\\]/u', $url) === 1) {
            return false;
        }

        // Any brace that is not a user token is not allowed.
        $stripped = preg_replace(self::TOKEN, 'x', $url) ?? '';

        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            return false;
        }

        $parts = parse_url($stripped);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // The address ends where the path starts: no token may sit in the scheme, the host or the port.
        $afterScheme = substr($url, (int) strpos($url, '//') + 2);
        $end = strcspn($afterScheme, '/?#');

        return preg_match(self::TOKEN, substr($afterScheme, 0, $end)) !== 1;
    }
}
