<?php

use App\Models\User;
use App\Support\Health\HealthChecker;
use App\Support\Health\Heartbeat;
use App\Support\Health\Role;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

/** @param  list<string>  $down  stores whose ping fails (defaults to both when $up is false) */
function fakeValkey(bool $up = true, array $down = []): void
{
    $down = $up ? $down : ['queue', 'cache'];

    Redis::shouldReceive('connection')->andReturnUsing(function (string $store) use ($down) {
        $connection = Mockery::mock();
        in_array($store, $down, true)
            ? $connection->shouldReceive('ping')->andThrow(new RuntimeException('Connection refused'))
            : $connection->shouldReceive('ping')->andReturn(true);

        return $connection;
    });
}

function breakPostgres(): void
{
    config([
        'database.connections.down' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/dashflow.sqlite',
        ],
        'dashflow.health.connection' => 'down',
    ]);
    DB::purge('down');
}

it('reports ready when every dependency is up', function () {
    fakeValkey();

    $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('answers 503 naming PostgreSQL when it is down', function () {
    fakeValkey();
    breakPostgres();

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('failed.0.check', 'PostgreSQL');
});

it('answers 503 naming each Valkey store when it is down', function (string $store, string $check) {
    fakeValkey(down: [$store]);

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonCount(1, 'failed')
        ->assertJsonPath('failed.0.check', $check)
        ->assertJsonPath('failed.0.reason', 'RuntimeException')
        ->assertDontSee('Connection refused');
})->with([['queue', 'Valkey queue'], ['cache', 'Valkey cache']]);

it('keeps liveness at 200 while PostgreSQL is down', function () {
    breakPostgres();

    $this->get('/health/live')->assertOk();
});

it('names PostgreSQL for every role when it is down', function (Role $role) {
    fakeValkey();
    breakPostgres();

    $report = app(HealthChecker::class)->check($role);

    expect($report->healthy())->toBeFalse()
        ->and($report->failures)->toHaveKey('PostgreSQL');
})->with(Role::cases());

it('names both Valkey stores for every role when they are down', function (Role $role) {
    fakeValkey(up: false);

    expect(app(HealthChecker::class)->check($role)->failures)
        ->toHaveKeys(['Valkey queue', 'Valkey cache']);
})->with(Role::cases());

it('names the Valkey store a role is refused on', function () {
    fakeValkey(down: ['cache']);

    $failures = app(HealthChecker::class)->check(Role::Realtime)->failures;

    expect($failures)->toHaveKey('Valkey cache')->not->toHaveKey('Valkey queue');
});

it('health command exits non-zero when the role is unhealthy', function () {
    fakeValkey();
    breakPostgres();

    $this->artisan('dashflow:health', ['role' => 'web'])->assertExitCode(1);
});

it('health command rejects an unknown role', function () {
    $this->artisan('dashflow:health', ['role' => 'nope'])->assertExitCode(2);
});

it('scheduler health needs a fresh heartbeat', function () {
    fakeValkey();
    $checker = app(HealthChecker::class);

    expect($checker->check(Role::Scheduler)->failures)->toHaveKey('scheduler');

    Heartbeat::beat();
    expect($checker->check(Role::Scheduler)->healthy())->toBeTrue();

    $this->travel(Heartbeat::MAX_AGE_SECONDS + 5)->seconds();
    expect($checker->check(Role::Scheduler)->failures)->toHaveKey('scheduler');
});

function fakeHorizon(array $masters): void
{
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->shouldReceive('names')->andReturn(array_keys($masters));
    $repository->shouldReceive('find')->andReturnUsing(fn ($name) => $masters[$name] ?? null);
    app()->instance(MasterSupervisorRepository::class, $repository);
}

it('worker health needs a running Horizon master on this host', function (Role $role) {
    fakeValkey();
    $checker = app(HealthChecker::class);
    $mine = MasterSupervisor::basename().'-abcd';

    fakeHorizon([]);
    expect($checker->check($role)->failures)->toHaveKey('Horizon');

    fakeHorizon([$mine => (object) ['status' => 'paused']]);
    expect($checker->check($role)->failures)->toHaveKey('Horizon');

    fakeHorizon(['other-host-abcd' => (object) ['status' => 'running']]);
    expect($checker->check($role)->failures)->toHaveKey('Horizon');

    fakeHorizon([$mine => (object) ['status' => 'running']]);
    expect($checker->check($role)->failures)->not->toHaveKey('Horizon');
})->with([Role::WorkerConnector, Role::WorkerCompute]);

it('local checks name nginx and Reverb when nothing listens', function () {
    fakeValkey();
    $closed = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($closed, false), ':'), 1);
    fclose($closed);
    config(['dashflow.health.web_port' => $port, 'reverb.servers.reverb.port' => $port]);
    $checker = app(HealthChecker::class);

    expect($checker->check(Role::Web, local: true)->failures)->toHaveKey('nginx')
        ->and($checker->check(Role::Realtime, local: true)->failures)->toHaveKey('Reverb');

    $open = stream_socket_server("tcp://127.0.0.1:{$port}");
    expect($checker->check(Role::Web, local: true)->failures)->not->toHaveKey('nginx')
        ->and($checker->check(Role::Realtime, local: true)->failures)->not->toHaveKey('Reverb');
    fclose($open);
});

it('denies the Horizon dashboard to everyone', function () {
    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(new User)->allows('viewHorizon'))->toBeFalse();
});
