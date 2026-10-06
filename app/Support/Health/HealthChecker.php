<?php

namespace App\Support\Health;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Throwable;

/**
 * Reports a role's health from the dependencies that role needs.
 *
 * Every role needs PostgreSQL and both Valkey stores (queue and cache). Each role adds its own check:
 * workers a running Horizon master, the scheduler a fresh heartbeat, and
 * (with $local) web and realtime their own listening port.
 */
final class HealthChecker
{
    public const POSTGRESQL = 'PostgreSQL';

    public const VALKEY_QUEUE = 'Valkey queue';

    public const VALKEY_CACHE = 'Valkey cache';

    public function check(Role $role, bool $local = false): HealthReport
    {
        $failures = [];
        $kinds = [];

        foreach ($this->checks($role, $local) as $name => $check) {
            try {
                $check();
            } catch (Throwable $e) {
                $failures[$name] = $e->getMessage();
                $kinds[$name] = class_basename($e);
            }
        }

        return new HealthReport($failures, $kinds);
    }

    /**
     * @return array<string, Closure(): void>
     */
    private function checks(Role $role, bool $local): array
    {
        $checks = [
            self::POSTGRESQL => function (): void {
                DB::connection(config('dashflow.health.connection'))->select('select 1');
            },
        ];

        // Each store is probed over its own connection, which authenticates as this role's ACL user.
        $checks[self::VALKEY_QUEUE] = function (): void {
            Redis::connection('queue')->ping();
        };
        $checks[self::VALKEY_CACHE] = function (): void {
            Redis::connection('cache')->ping();
        };

        switch ($role) {
            case Role::Web:
                if ($local) {
                    $checks['nginx'] = fn () => $this->listening((int) config('dashflow.health.web_port'));
                }
                break;
            case Role::Realtime:
                if ($local) {
                    $checks['Reverb'] = fn () => $this->listening((int) config('reverb.servers.reverb.port'));
                }
                break;
            case Role::Scheduler:
                $checks['scheduler'] = function (): void {
                    $age = Heartbeat::age();
                    if ($age === null) {
                        throw new \RuntimeException('no scheduler heartbeat recorded');
                    }
                    if ($age > Heartbeat::MAX_AGE_SECONDS) {
                        throw new \RuntimeException("scheduler heartbeat is {$age}s old");
                    }
                };
                break;
            case Role::WorkerConnector:
            case Role::WorkerCompute:
                $checks['Horizon'] = function (): void {
                    // The master's name carries a per-process token: match on this host's prefix.
                    $repository = app(MasterSupervisorRepository::class);
                    $prefix = MasterSupervisor::basename().'-';
                    $running = collect($repository->names())
                        ->filter(fn ($name) => str_starts_with((string) $name, $prefix))
                        ->map(fn ($name) => $repository->find($name))
                        ->filter(fn ($master) => $master !== null && ($master->status ?? null) === 'running');

                    if ($running->isEmpty()) {
                        throw new \RuntimeException('Horizon master supervisor is not running');
                    }
                };
                break;
        }

        return $checks;
    }

    private function listening(int $port): void
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 2);
        if ($socket === false) {
            throw new \RuntimeException("nothing listening on port {$port}");
        }
        fclose($socket);
    }
}
