<?php

namespace App\Platform\EditLock;

use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * The two `pending_input` settings of the soft lock. Neither has a default: the TTL
 * (`dashflow.tunables.timeouts.edit_lock_ttl`, seconds) unset means the lock is disabled, and the flush timeout
 * (`dashflow.edit_lock.flush_timeout_seconds`, seconds) unset means a take-over does not wait for the holder.
 */
final class EditLockSettings
{
    /** @var array<string, true> settings already warned about in this process: one warning each, never a value */
    private static array $warned = [];

    public function __construct(private readonly Repository $config, private readonly ?LoggerInterface $log = null) {}

    public static function forgetWarnings(): void
    {
        self::$warned = [];
    }

    /** Whole seconds a lock lives without a heartbeat; null disables the lock. */
    public function ttl(): ?int
    {
        return $this->whole('dashflow.tunables.timeouts.edit_lock_ttl.value');
    }

    /** Whole seconds a take-over waits for the holder's flush; null: it does not wait. */
    public function flushTimeout(): ?int
    {
        return $this->whole('dashflow.edit_lock.flush_timeout_seconds.value');
    }

    private function whole(string $key): ?int
    {
        $value = $this->config->get($key);

        if ((is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/D', trim($value)) === 1)) && (int) $value > 0) {
            return (int) $value;
        }

        // Set but unusable (`60s`, `060`, `1.5`, `0`): the setting stays unset, and an operator is told once which one, never its value.
        if ($value !== null && $value !== '' && ! isset(self::$warned[$key])) {
            self::$warned[$key] = true;
            $this->log?->warning('dashflow.edit_lock.setting_invalid', ['setting' => $key]);
        }

        return null;
    }
}
