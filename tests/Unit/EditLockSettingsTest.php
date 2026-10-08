<?php

use App\Platform\EditLock\EditLockResourceMissing;
use App\Platform\EditLock\EditLockResources;
use App\Platform\EditLock\EditLockSettings;
use App\Platform\EditLock\LockEpochs;
use Illuminate\Config\Repository;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

// Story 2.8: the soft lock's two `pending_input` settings have no default. An unset, zero or malformed value is "unset":
// the TTL then disables the lock and the flush timeout means a take-over does not wait.
function lockSettings(mixed $ttl, mixed $flush, ?LoggerInterface $log = null): EditLockSettings
{
    return new EditLockSettings(new Repository([
        'dashflow' => ['tunables' => ['timeouts' => ['edit_lock_ttl' => ['value' => $ttl]]], 'edit_lock' => ['flush_timeout_seconds' => ['value' => $flush]]],
    ]), $log);
}

it('has no default for the TTL or the flush timeout', function () {
    $config = require dirname(__DIR__, 2).'/config/dashflow.php';

    expect($config['edit_lock']['flush_timeout_seconds'])->toMatchArray(['env' => 'DASHFLOW_EDIT_LOCK_FLUSH_TIMEOUT_SECONDS', 'value' => null, 'pending_input' => true])
        ->and($config['tunables']['timeouts']['edit_lock_ttl'])->toMatchArray(['env' => 'DASHFLOW_EDIT_LOCK_TTL', 'value' => null, 'pending_input' => true]);
});

it('reads whole positive seconds and treats anything else as unset', function (mixed $value, ?int $expected) {
    expect(lockSettings($value, $value)->ttl())->toBe($expected)
        ->and(lockSettings($value, $value)->flushTimeout())->toBe($expected);
})->with([
    'null' => [null, null],
    'empty' => ['', null],
    'zero' => ['0', null],
    'negative' => ['-5', null],
    'text' => ['soon', null],
    'decimal' => ['1.5', null],
    'string' => ['45', 45],
    'int' => [90, 90],
    'padded' => [' 30 ', 30],
]);

it('refuses a resource type that is not a lowercase snake_case name and one that is not registered', function () {
    $epochs = new class implements LockEpochs
    {
        public function current(string $workspaceId, string $id): ?int
        {
            return 1;
        }

        public function increment(string $workspaceId, string $id): ?int
        {
            return 2;
        }
    };
    $resources = new EditLockResources;
    $resources->register('data_source', $epochs);

    expect($resources->for('data_source'))->toBe($epochs)
        ->and(fn () => $resources->for('dashboard'))->toThrow(EditLockResourceMissing::class)
        ->and(fn () => $resources->register('Bad Type', $epochs))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $resources->register('a:b', $epochs))->toThrow(InvalidArgumentException::class);
});

it('warns once, naming the setting and never its value, when a configured value cannot be read, and stays unset', function (string $bad) {
    EditLockSettings::forgetWarnings();
    $log = new class extends AbstractLogger
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [(string) $message, $context];
        }
    };
    $settings = lockSettings($bad, $bad, $log);

    expect($settings->ttl())->toBeNull()->and($settings->ttl())->toBeNull()->and($settings->flushTimeout())->toBeNull()
        ->and($log->records)->toHaveCount(2)
        ->and(array_column(array_column($log->records, 1), 'setting'))->toBe(['dashflow.tunables.timeouts.edit_lock_ttl.value', 'dashflow.edit_lock.flush_timeout_seconds.value'])
        ->and(json_encode($log->records))->not->toContain($bad);

    // An unset value is not a mistake.
    EditLockSettings::forgetWarnings();
    lockSettings(null, '', $log)->ttl();
    expect($log->records)->toHaveCount(2);
})->with(['60s', '060', '1.5', '0']);
