<?php

namespace App\Platform\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read access to the Workspace's settings row (`workspace_settings`, owned by the kernel). Epic 8 adds
 * editing; until then the row is only read. It runs inside the request's Workspace transaction, so row-level
 * security returns the active Workspace's row and nothing else; with no Workspace context there is no row.
 *
 * Whatever is stored is treated as untrusted on the way out: a link needs a text label and an http or https
 * address, and the contact address may also be a `mailto:` link.
 */
final class WorkspaceSettings
{
    /**
     * The help links the Workspace Admin configured, in stored order.
     *
     * @return list<array{label: string, url: string}>
     */
    public function helpLinks(): array
    {
        $row = $this->row();
        $decoded = $row === null ? null : json_decode((string) ($row['help_links'] ?? '[]'), true);
        $links = [];

        foreach (is_array($decoded) ? $decoded : [] as $link) {
            if (! is_array($link) || ! is_string($link['label'] ?? null) || ! is_string($link['url'] ?? null)) {
                continue;
            }

            $label = trim($link['label']);

            if ($label === '' || ! self::isWebAddress($link['url'])) {
                continue;
            }

            $links[] = ['label' => $label, 'url' => $link['url']];
        }

        return $links;
    }

    /** The address behind "Contact your workspace administrator", or null when none is configured. */
    public function contactHref(): ?string
    {
        $href = $this->row()['contact_href'] ?? null;

        if (! is_string($href) || trim($href) === '') {
            return null;
        }

        return self::isWebAddress($href) || self::isMailAddress($href) ? $href : null;
    }

    /** @var array<string, mixed>|null|false The row, read once per instance; false until read. */
    private array|null|false $loaded = false;

    /**
     * A nested transaction (a savepoint inside the request's), so a failed read rolls back to it and does not
     * leave the request's own transaction aborted. A failure is logged and the page shows the empty list.
     *
     * @return array<string, mixed>|null
     */
    private function row(): ?array
    {
        if ($this->loaded !== false) {
            return $this->loaded;
        }

        try {
            $row = DB::transaction(fn () => DB::table('workspace_settings')->select(['help_links', 'contact_href'])->first());

            return $this->loaded = $row === null ? null : (array) $row;
        } catch (Throwable $e) {
            Log::warning('workspace_settings.read_failed', ['exception' => $e::class]);

            return $this->loaded = null;
        }
    }

    public static function isWebAddress(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    private static function isMailAddress(string $url): bool
    {
        return preg_match('/^mailto:[^\s<>"]+$/i', $url) === 1 && strlen($url) > 7;
    }
}
