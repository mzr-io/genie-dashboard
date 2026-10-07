<?php

use App\Modules\Connector\Contracts\EgressGrantRefused;
use App\Modules\Connector\Contracts\EgressGrants;
use App\Modules\Connector\Contracts\EgressGuard;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\HostResolver;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeResolver;

// Story 2.2 against the real PostgreSQL: grants (operator role, RLS, audit), the guard reading the allowlist and the
// grants as the Workspace, the security event and the SSRF alert, and the three operator commands.

const EGR_PW = 'correct horse battery';

function egressReset(array $dns = []): FakeResolver
{
    $resolver = new FakeResolver($dns);
    app()->instance(HostResolver::class, $resolver);
    config(['dashflow.egress.operator_password_hash.value' => Hash::make(EGR_PW)]);

    return $resolver;
}

function grantRows(string $where = 'true'): array
{
    return Cluster::rows(Cluster::superuser(), "select * from egress_grants where {$where} order by granted_at, id");
}

function auditActions(string $like): array
{
    return array_column(Cluster::rows(Cluster::superuser(), 'select action from audit_events where action like ? order by occurred_at, id', [$like]), 'action');
}

it('stores a grant for one Workspace, writes operator_audit and mirrors it into the Workspace audit log with a hashed reason', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $granted = app(EgressGrants::class)->grant($a, '10.20.0.0/16', 'Customer VPN to the ERP', 'operator:alice');

    $row = grantRows()[0];
    expect($row['id'])->toBe($granted->id)
        ->and($row['workspace_id'])->toBe($a)
        ->and($row['cidr'])->toBe('10.20.0.0/16')
        ->and($row['reason'])->toBe('Customer VPN to the ERP')
        ->and($row['granted_by'])->toBe('operator:alice')
        ->and($row['revoked_at'])->toBeNull()
        ->and($granted->mirrored)->toBeTrue();

    $operator = Cluster::rows(Cluster::superuser(), 'select * from operator_audit')[0];
    $details = json_decode($operator['details'], true);
    expect($operator['action'])->toBe('connector.egress_grant.created')
        ->and($operator['actor'])->toBe('operator:alice')
        ->and($operator['workspace_id'])->toBe($a)
        ->and($details['cidr'])->toBe('10.20.0.0/16')
        ->and($details['grant_id'])->toBe($granted->id)
        ->and($details['reason'])->not->toContain('ERP');

    $mirror = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'connector.egress_grant.created'")[0];
    $after = json_decode($mirror['after_state'], true);
    expect($mirror['workspace_id'])->toBe($a)
        ->and($mirror['actor'])->toBe('operator:alice')
        ->and($after['cidr'])->toBe('10.20.0.0/16')
        ->and($after['grant_id'])->toBe($granted->id)
        ->and($after['grant_reason'])->not->toContain('ERP')
        ->and($after['grant_reason'])->toBe($details['reason']);
});

it('refuses a CIDR that is undeniable, overlaps one, overlaps the deployment, is public, is malformed or is already granted, and writes nothing', function (string $cidr, string $problem) {
    egressReset();
    config(['dashflow.egress.deployment_cidrs.value' => '172.20.0.0/16']);
    $a = Cluster::workspace('A');
    app(EgressGrants::class)->grant($a, '10.20.0.0/16', 'first', 'operator:alice');
    $before = count(grantRows());

    try {
        app(EgressGrants::class)->grant($a, $cidr, 'because', 'operator:alice');
        $thrown = null;
    } catch (EgressGrantRefused $e) {
        $thrown = $e->problem->value;
    }

    expect($thrown)->toBe($problem)
        ->and(count(grantRows()))->toBe($before)
        ->and(count(Cluster::rows(Cluster::superuser(), 'select 1 from operator_audit')))->toBe(1);
})->with([
    'loopback' => ['127.0.0.0/8', 'undeniable'],
    'loopback host' => ['127.0.0.1/32', 'undeniable'],
    'link-local' => ['169.254.0.0/16', 'undeniable'],
    'metadata' => ['169.254.169.254/32', 'undeniable'],
    'IPv6 metadata' => ['fd00:ec2::254/128', 'undeniable'],
    'multicast' => ['224.0.0.0/4', 'undeniable'],
    'documentation' => ['192.0.2.0/24', 'undeniable'],
    'IPv4-mapped' => ['::ffff:0:0/96', 'undeniable'],
    'everything' => ['0.0.0.0/0', 'overlaps_deployment'],
    'all IPv6' => ['::/0', 'overlaps_undeniable'],
    'ULA wide enough to hold the metadata address' => ['fd00::/8', 'overlaps_undeniable'],
    'CGNAT holding the Alibaba metadata address' => ['100.64.0.0/10', 'overlaps_undeniable'],
    'Azure wireserver' => ['168.63.129.16/32', 'undeniable'],
    'all ULA' => ['fc00::/7', 'overlaps_undeniable'],
    'deployment own' => ['172.20.0.0/16', 'overlaps_deployment'],
    'inside deployment own' => ['172.20.5.0/24', 'overlaps_deployment'],
    'containing deployment own' => ['172.16.0.0/12', 'overlaps_deployment'],
    'public' => ['8.8.8.0/24', 'not_private'],
    'half public' => ['10.0.0.0/7', 'not_private'],
    'host bits set' => ['10.20.0.1/16', 'invalid'],
    'not a network' => ['ten', 'invalid'],
    'duplicate' => ['10.20.0.0/16', 'already_granted'],
    'covered by an existing grant' => ['10.20.5.0/24', 'already_granted'],
]);

it('allows a wider grant after a narrower one, and a revoked range to be granted again', function () {
    egressReset();
    $a = Cluster::workspace('A');
    $grants = app(EgressGrants::class);

    $grants->grant($a, '10.20.5.0/24', 'narrow', 'operator:alice');
    $grants->grant($a, '10.20.0.0/16', 'wide', 'operator:alice');
    $grants->revoke($a, '10.20.0.0/16', 'done', 'operator:alice');
    $again = $grants->grant($a, '10.20.0.0/16', 'again', 'operator:alice');

    expect(count(grantRows()))->toBe(3)
        ->and($grants->activeCidrs($a))->toBe(['10.20.5.0/24', '10.20.0.0/16'])
        ->and($again->mirrored)->toBeTrue();
});

it('refuses a missing or unprintable reason and an unknown Workspace, and writes nothing', function () {
    egressReset();
    $grants = app(EgressGrants::class);
    $a = Cluster::workspace('A');

    foreach (['', '   ', "line\nbreak", str_repeat('x', 501)] as $reason) {
        expect(fn () => $grants->grant($a, '10.20.0.0/16', $reason, 'operator:alice'))->toThrow(EgressGrantRefused::class);
    }

    expect(fn () => $grants->grant('018f0000-0000-7000-8000-0000000000ff', '10.20.0.0/16', 'because', 'operator:alice'))
        ->toThrow(EgressGrantRefused::class, 'No such Workspace');

    expect(grantRows())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select 1 from operator_audit'))->toBe([]);
});

it('revokes by setting revoked_at, keeps the row, audits it and then denies the range', function () {
    $resolver = egressReset(['erp.example.com' => ['10.20.5.5']]);
    $a = Cluster::workspace('A');
    Cluster::seedHostEntry($a, 'erp.example.com');
    $grants = app(EgressGrants::class);
    $granted = $grants->grant($a, '10.20.0.0/16', 'ERP', 'operator:alice');
    $guard = app(EgressGuard::class);

    expect($guard->decide($a, 'https://erp.example.com/', record: false)->allowed)->toBeTrue();

    $revoked = $grants->revoke($a, '10.20.0.0/16', 'contract ended', 'operator:bob');

    $row = grantRows()[0];
    expect($revoked->id)->toBe($granted->id)
        ->and($row['revoked_at'])->not->toBeNull()
        ->and($row['revoked_by'])->toBe('operator:bob')
        ->and($grants->activeCidrs($a))->toBe([])
        ->and($guard->decide($a, 'https://erp.example.com/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select action from operator_audit order by occurred_at, id'), 'action'))
        ->toBe(['connector.egress_grant.created', 'connector.egress_grant.revoked'])
        ->and(auditActions('connector.egress_grant.%'))->toBe(['connector.egress_grant.created', 'connector.egress_grant.revoked'])
        ->and($resolver->lookups)->not->toBe([]);
});

it('refuses to revoke a range that is not granted or already revoked', function () {
    egressReset();
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $grants = app(EgressGrants::class);
    $grants->grant($a, '10.20.0.0/16', 'ERP', 'operator:alice');

    expect(fn () => $grants->revoke($a, '10.30.0.0/16', 'x', 'operator:alice'))->toThrow(EgressGrantRefused::class, 'no active grant')
        ->and(fn () => $grants->revoke($b, '10.20.0.0/16', 'x', 'operator:alice'))->toThrow(EgressGrantRefused::class, 'no active grant');

    $grants->revoke($a, '10.20.0.0/16', 'x', 'operator:alice');

    expect(fn () => $grants->revoke($a, '10.20.0.0/16', 'x', 'operator:alice'))->toThrow(EgressGrantRefused::class, 'no active grant');
});

it('allows a granted private range for that Workspace only', function () {
    egressReset(['erp.example.com' => ['10.20.5.5']]);
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedHostEntry($a, 'erp.example.com');
    Cluster::seedHostEntry($b, 'erp.example.com');
    app(EgressGrants::class)->grant($a, '10.20.0.0/16', 'ERP', 'operator:alice');
    $guard = app(EgressGuard::class);

    $mine = $guard->decide($a, 'https://erp.example.com/v1', record: false);
    $theirs = $guard->decide($b, 'https://erp.example.com/v1', record: false);

    expect($mine->allowed)->toBeTrue()
        ->and($mine->pinnedIp)->toBe('10.20.5.5')
        ->and($theirs->allowed)->toBeFalse()
        ->and($theirs->reason)->toBe(EgressReason::HostNotAllowlisted);
});

it('lets no grant lift an undeniable address, even a grant written straight into the table', function () {
    egressReset(['evil.example.com' => ['169.254.169.254'], 'lo.example.com' => ['127.0.0.1']]);
    $a = Cluster::workspace('A');
    Cluster::seedHostEntry($a, 'evil.example.com');
    Cluster::seedHostEntry($a, 'lo.example.com');
    Cluster::seedEgressGrant($a, '169.254.0.0/16');
    Cluster::seedEgressGrant($a, '127.0.0.0/8');
    $guard = app(EgressGuard::class);

    expect($guard->decide($a, 'https://evil.example.com/', record: false)->reason)->toBe(EgressReason::BlockedAddress)
        ->and($guard->decide($a, 'https://lo.example.com/', record: false)->reason)->toBe(EgressReason::BlockedAddress);
});

it('decides from the allowlist of the Workspace only', function () {
    egressReset(['api.example.com' => ['93.184.216.34']]);
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedHostEntry($a, 'api.example.com');
    $guard = app(EgressGuard::class);

    expect($guard->decide($a, 'https://api.example.com/', record: false)->allowed)->toBeTrue()
        ->and($guard->decide($b, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted);
});

it('matches the scheme of the allowlist entry too, in both directions', function () {
    egressReset(['api.example.com' => ['93.184.216.34'], 'plain.example.com' => ['93.184.216.34']]);
    $a = Cluster::workspace('A');
    Cluster::seedHostEntry($a, 'api.example.com', 443, 'https');
    Cluster::seedHostEntry($a, 'plain.example.com', 80, 'http');
    $guard = app(EgressGuard::class);

    expect($guard->decide($a, 'http://api.example.com:443/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and($guard->decide($a, 'https://plain.example.com:80/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and($guard->decide($a, 'https://api.example.com/', record: false)->allowed)->toBeTrue()
        ->and($guard->decide($a, 'http://plain.example.com/', record: false)->allowed)->toBeTrue();
});

it('records a denial as a connector.egress.blocked security event with reason, host and port and never an address', function () {
    egressReset(['lo.example.com' => ['127.0.0.1']]);
    $a = Cluster::workspace('A');
    Cluster::seedHostEntry($a, 'lo.example.com');
    $guard = app(EgressGuard::class);

    $guard->decide($a, 'https://lo.example.com/secret?token=abc');
    $guard->decide($a, 'https://nope.example.com:8443/');
    $guard->decide($a, 'https://api.example.com@evil.example/');
    $guard->decide($a, 'https://lo.example.com/', record: false);

    $events = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'connector.egress.blocked' order by occurred_at, id");
    $states = array_map(fn ($e) => json_decode($e['after_state'], true), $events);

    expect($events)->toHaveCount(3)
        ->and($states[0])->toEqual(['reason' => 'blocked_address', 'host' => 'lo.example.com', 'port' => 443])
        ->and($states[1])->toEqual(['reason' => 'host_not_allowlisted', 'host' => 'nope.example.com', 'port' => 8443])
        ->and($states[2]['reason'])->toBe('invalid_url')
        ->and(array_unique(array_column($events, 'security')))->toBe([true])
        ->and(array_unique(array_column($events, 'workspace_id')))->toBe([$a]);

    $dump = json_encode($events);
    expect($dump)->not->toContain('127.0.0.1')->and($dump)->not->toContain('token=abc')->and($dump)->not->toContain('secret');
});

it('keeps the security event when the surrounding transaction rolls back', function () {
    egressReset();
    $a = Cluster::workspace('A');
    $guard = app(EgressGuard::class);

    try {
        app(WorkspaceTransaction::class)->run($a, function () use ($guard, $a): void {
            $guard->decide($a, 'https://nope.example.com/');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(auditActions('connector.egress.blocked'))->toBe(['connector.egress.blocked']);
});

it('fires dashflow.connector.ssrf_blocked once a Workspace passes the threshold in the window, with no address in the labels', function () {
    egressReset(['lo.example.com' => ['127.0.0.1']]);
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedHostEntry($a, 'lo.example.com');
    Cluster::seedHostEntry($b, 'lo.example.com');
    config(['dashflow.egress.alert_threshold.value' => '2', 'dashflow.egress.alert_window.value' => '60']);
    Cache::flush();

    $spy = new class implements MetricEmitter
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $emitted = [];

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->emitted[] = [$name, $labels];
        }
    };
    app()->instance(MetricEmitter::class, $spy);

    $guard = app(EgressGuard::class);
    $guard->decide($a, 'https://lo.example.com/');
    $guard->decide($a, 'https://lo.example.com/');
    $guard->decide($b, 'https://lo.example.com/');

    expect($spy->emitted)->toBe([], 'two blocks do not pass a threshold of two');

    $guard->decide($a, 'https://lo.example.com/');

    expect($spy->emitted)->toBe([['dashflow.connector.ssrf_blocked', ['workspace_id' => $a, 'reason' => 'blocked_address']]])
        ->and(json_encode($spy->emitted))->not->toContain('127.0.0.1');
});

it('fires no alert while the threshold or the window is unset', function (?string $threshold, ?string $window) {
    egressReset();
    $a = Cluster::workspace('A');
    config(['dashflow.egress.alert_threshold.value' => $threshold, 'dashflow.egress.alert_window.value' => $window]);
    Cache::flush();

    $spy = new class implements MetricEmitter
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $emitted = [];

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->emitted[] = [$name, $labels];
        }
    };
    app()->instance(MetricEmitter::class, $spy);

    foreach (range(1, 5) as $i) {
        app(EgressGuard::class)->decide($a, 'https://nope.example.com/');
    }

    expect($spy->emitted)->toBe([])->and(auditActions('connector.egress.blocked'))->toHaveCount(5);
})->with([[null, null], ['1', null], [null, '60'], ['0', '60'], ['1', 'soon']]);

it('keeps the guard reading grants as the Workspace: a table row of another Workspace never applies', function () {
    egressReset(['erp.example.com' => ['10.20.5.5']]);
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedHostEntry($b, 'erp.example.com');
    Cluster::seedEgressGrant($a, '10.20.0.0/16');

    expect(app(EgressGrants::class)->activeCidrs($b))->toBe([])
        ->and(app(EgressGuard::class)->decide($b, 'https://erp.example.com/', record: false)->allowed)->toBeFalse();
});

it('ignores revoked rows when the guard reads grants', function () {
    egressReset();
    $a = Cluster::workspace('A');
    Cluster::seedEgressGrant($a, '10.20.0.0/16', revoked: true);
    Cluster::seedEgressGrant($a, '10.30.0.0/16');

    expect(app(EgressGrants::class)->activeCidrs($a))->toBe(['10.30.0.0/16']);
});

// ---- the commands

it('prints allowed with the pinned IP and the decision trail for dashflow:egress:check, and sends no request', function () {
    egressReset(['api.example.com' => ['93.184.216.34']]);
    $a = Cluster::workspace('A');
    Cluster::seedHostEntry($a, 'api.example.com');

    $code = Artisan::call('dashflow:egress:check', ['workspace' => $a, 'url' => 'https://api.example.com/v1']);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('allowed')->and($output)->toContain('pinned ip: 93.184.216.34')
        ->and($output)->toContain('decision trail:')->toContain('allowlist:matched')->toContain('address:public')
        ->and(auditActions('connector.egress.%'))->toBe([]);
});

it('prints the denial, the error code and the message key for dashflow:egress:check, without auditing or leaking the address', function () {
    egressReset(['lo.example.com' => ['127.0.0.1']]);
    $a = Cluster::workspace('A');
    Cluster::seedHostEntry($a, 'lo.example.com');

    $code = Artisan::call('dashflow:egress:check', ['workspace' => $a, 'url' => 'https://lo.example.com/']);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('denied: blocked_address')->toContain('connector.ssrf_blocked')->toContain('msg:blocked-address')
        ->and($output)->not->toContain('127.0.0.1')
        ->and(auditActions('connector.egress.%'))->toBe([]);

    expect(Artisan::call('dashflow:egress:check', ['workspace' => 'nope', 'url' => 'https://lo.example.com/']))->toBe(2);
});

it('grants through dashflow:egress:grant after the password is confirmed', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsQuestion('Operator password', EGR_PW)
        ->assertExitCode(0);

    expect(grantRows())->toHaveCount(1)
        ->and(grantRows()[0]['granted_by'])->toMatch('/\Aoperator:[a-z0-9_.-]+\z/')
        ->and(auditActions('connector.egress_grant.created'))->toHaveCount(1);
});

it('refuses the grant command with a wrong password three times, an unset hash or a hash that is not bcrypt, and writes nothing', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsQuestion('Operator password', 'wrong')
        ->expectsQuestion('Operator password', 'wrong')
        ->expectsQuestion('Operator password', 'wrong')
        ->assertExitCode(1);

    config(['dashflow.egress.operator_password_hash.value' => null]);
    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsOutputToContain('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH is not set')
        ->assertExitCode(1);

    // Not a bcrypt hash: refused before any prompt (an unanswered prompt would fail the test).
    config(['dashflow.egress.operator_password_hash.value' => hash('sha256', EGR_PW)]);
    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsOutputToContain('is not a bcrypt hash')
        ->assertExitCode(1);
    $this->artisan('dashflow:egress:revoke', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsOutputToContain('is not a bcrypt hash')
        ->assertExitCode(1);

    expect(grantRows())->toBe([])->and(Cluster::rows(Cluster::superuser(), 'select 1 from operator_audit'))->toBe([]);
});

it('succeeds on the second try after one wrong password', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsQuestion('Operator password', 'wrong')
        ->expectsQuestion('Operator password', EGR_PW)
        ->assertExitCode(0);

    expect(grantRows())->toHaveCount(1);
});

it('refuses a bad workspace, CIDR or missing reason before asking for the password, and an undeniable CIDR after it, with no row', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $this->artisan('dashflow:egress:grant', ['workspace' => 'nope', 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])->assertExitCode(2);
    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.1/16', '--reason' => 'ERP'])->assertExitCode(2);
    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16'])->assertExitCode(2);

    foreach (['127.0.0.0/8', '169.254.169.254/32', '8.8.8.0/24'] as $cidr) {
        $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => $cidr, '--reason' => 'ERP'])
            ->expectsQuestion('Operator password', EGR_PW)
            ->assertExitCode(1);
    }

    expect(grantRows())->toBe([]);
});

it('refuses a duplicate grant through the command and revokes through dashflow:egress:revoke', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '192.168.0.0/16', '--reason' => 'ERP'])
        ->expectsQuestion('Operator password', EGR_PW)->assertExitCode(0);
    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '192.168.0.0/16', '--reason' => 'ERP'])
        ->expectsQuestion('Operator password', EGR_PW)->assertExitCode(1);

    $this->artisan('dashflow:egress:revoke', ['workspace' => $a, 'cidr' => '192.168.0.0/16', '--reason' => 'done'])
        ->expectsQuestion('Operator password', 'wrong')
        ->expectsQuestion('Operator password', 'wrong')
        ->expectsQuestion('Operator password', 'wrong')
        ->assertExitCode(1);
    expect(grantRows()[0]['revoked_at'])->toBeNull();

    $this->artisan('dashflow:egress:revoke', ['workspace' => $a, 'cidr' => '192.168.0.0/16', '--reason' => 'done'])
        ->expectsQuestion('Operator password', EGR_PW)->assertExitCode(0);
    $this->artisan('dashflow:egress:revoke', ['workspace' => $a, 'cidr' => '192.168.0.0/16', '--reason' => 'done'])
        ->expectsQuestion('Operator password', EGR_PW)->assertExitCode(1);

    expect(grantRows())->toHaveCount(1)
        ->and(grantRows()[0]['revoked_at'])->not->toBeNull()
        ->and(auditActions('connector.egress_grant.%'))->toBe(['connector.egress_grant.created', 'connector.egress_grant.revoked']);
});

it('keeps the grant reason and the CIDR-free secrets out of every log of the operator', function () {
    egressReset();
    $a = Cluster::workspace('A');

    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'Highly sensitive justification'])
        ->expectsQuestion('Operator password', EGR_PW)->assertExitCode(0);

    $dump = Cluster::rows(Cluster::superuser(), "select (select coalesce(string_agg(t::text, ' '), '') from operator_audit t) || (select coalesce(string_agg(t::text, ' '), '') from audit_events t) as d")[0]['d'];

    expect($dump)->not->toContain('Highly sensitive')->and($dump)->not->toContain(EGR_PW);
});

it('stores the grant and its operator_audit row when the Workspace mirror fails, and the command warns and exits 1', function () {
    egressReset();
    $a = Cluster::workspace('A');
    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('mirror down'));
    app()->instance(Audit::class, $audit);
    Log::spy();

    $this->artisan('dashflow:egress:grant', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'ERP'])
        ->expectsQuestion('Operator password', EGR_PW)
        ->expectsOutputToContain('mirroring it into the Workspace audit log failed')
        ->assertExitCode(1);

    expect(grantRows())->toHaveCount(1)
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select action from operator_audit'), 'action'))->toBe(['connector.egress_grant.created'])
        ->and(auditActions('connector.egress_grant.%'))->toBe([]);

    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c) => $m === 'connector.egress_grant.mirror_failed' && $c['exception'] === RuntimeException::class);
});

it('revokes and keeps the operator_audit row when the Workspace mirror fails, and the command warns and exits 1', function () {
    egressReset();
    $a = Cluster::workspace('A');
    app(EgressGrants::class)->grant($a, '10.20.0.0/16', 'ERP', 'operator:alice');
    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('mirror down'));
    app()->instance(Audit::class, $audit);

    $this->artisan('dashflow:egress:revoke', ['workspace' => $a, 'cidr' => '10.20.0.0/16', '--reason' => 'done'])
        ->expectsQuestion('Operator password', EGR_PW)
        ->expectsOutputToContain('mirroring it into the Workspace audit log failed')
        ->assertExitCode(1);

    expect(grantRows()[0]['revoked_at'])->not->toBeNull()
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select action from operator_audit order by occurred_at, id'), 'action'))
        ->toBe(['connector.egress_grant.created', 'connector.egress_grant.revoked']);
});
