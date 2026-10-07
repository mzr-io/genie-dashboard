<?php

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditSerializers;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

function counts(): array
{
    return [
        (int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from audit_events')[0]['n'],
        (int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_events')[0]['n'],
    ];
}

it('commits the audit event and the outbox event with the change, with the full envelope', function () {
    $a = Cluster::workspace('A');
    $membership = '018f0000-0000-7000-8000-000000000001';

    $envelope = app(WorkspaceTransaction::class)->run($a, function () use ($membership) {
        app(Audit::class)->record(AuditAction::AccessRoleChanged, ['membership_id' => $membership, 'role' => 'admin'], ['role' => 'user'], 'membership:'.$membership, 'system');

        return app(Outbox::class)->emit(AuditAction::AccessRoleChanged, 'membership:'.$membership, ['membership_id' => $membership, 'role' => 'admin', 'active' => true], 1, 'system');
    });

    expect(counts())->toBe([1, 1]);

    $row = Cluster::rows(Cluster::superuser(), 'select * from outbox_events')[0];
    expect(array_keys($envelope->toArray()))->toBe(['event_id', 'type', 'v', 'workspace_id', 'subject', 'subject_seq', 'occurred_at', 'actor', 'request_id', 'data'])
        ->and($row['id'])->toBe($envelope->eventId)
        ->and($row['workspace_id'])->toBe($a)
        ->and($row['type'])->toBe('access.role.changed')
        ->and((int) $row['subject_seq'])->toBe(1)
        ->and($row['sent_at'])->toBeNull()
        ->and(json_decode($row['data'], true))->toEqualCanonicalizing(['membership_id' => $membership, 'role' => 'admin', 'active' => true]);

    $audit = Cluster::rows(Cluster::superuser(), 'select * from audit_events')[0];
    expect($audit['action'])->toBe('access.role.changed')
        ->and($audit['security'])->toBeFalse()
        ->and(json_decode($audit['after_state'], true))->toEqualCanonicalizing(['membership_id' => $membership, 'role' => 'admin']);
});

it('removes both rows when the transaction rolls back', function () {
    $a = Cluster::workspace('A');

    expect(fn () => app(WorkspaceTransaction::class)->run($a, function () {
        app(Audit::class)->record(AuditAction::AccessRoleChanged, ['role' => 'admin']);
        app(Outbox::class)->emit(AuditAction::AccessRoleChanged, 'membership:1', ['role' => 'admin']);
        throw new RuntimeException('command failed');
    }))->toThrow(RuntimeException::class);

    expect(counts())->toBe([0, 0]);
});

it('refuses to run outside a Workspace transaction and stores nothing', function () {
    expect(fn () => app(Audit::class)->record(AuditAction::AccessRoleChanged))->toThrow(LogicException::class);
    expect(fn () => app(Outbox::class)->emit(AuditAction::AccessRoleChanged, 'membership:1'))->toThrow(LogicException::class);

    // A plain transaction with no Workspace context is not enough either.
    DB::transaction(function () {
        expect(fn () => app(Audit::class)->record(AuditAction::AccessRoleChanged))->toThrow(LogicException::class);
        expect(fn () => app(Outbox::class)->emit(AuditAction::AccessRoleChanged, 'membership:1'))->toThrow(LogicException::class);
    });

    expect(counts())->toBe([0, 0]);
});

it('throws on an action that is not an AuditAction value, storing nothing', function () {
    $a = Cluster::workspace('A');

    app(WorkspaceTransaction::class)->run($a, function () {
        expect(fn () => app(Audit::class)->record('access.role.exploded'))->toThrow(InvalidArgumentException::class);
        expect(fn () => app(Outbox::class)->emit('made.up.event', 'membership:1'))->toThrow(InvalidArgumentException::class);
    });

    expect(counts())->toBe([0, 0]);
});

it('stores allowlisted fields only, with free text as a keyed hash and never raw', function () {
    $a = Cluster::workspace('A');

    app(WorkspaceTransaction::class)->run($a, fn () => app(Audit::class)->record(AuditAction::AccessAttributeChanged, [
        'attribute_key' => 'region',
        'attribute_value' => 'Secret Free Text',
        'password' => 'hunter2',
        'user_id' => 42,
    ]));

    $raw = Cluster::rows(Cluster::superuser(), 'select after_state::text as s from audit_events')[0]['s'];
    $state = json_decode($raw, true);

    expect($state)->toHaveCount(3)->toHaveKeys(['attribute_key', 'attribute_value', 'user_id'])->not->toHaveKey('password')
        ->and($state['attribute_key'])->toBe('region')
        ->and($state['user_id'])->toBe(42)
        ->and($state['attribute_value'])->toStartWith('hmac-sha256:')
        ->and($raw)->not->toContain('Secret Free Text')->not->toContain('hunter2');
});

it('persists a security event when the caller\'s transaction rolls back', function () {
    $a = Cluster::workspace('A');

    expect(fn () => app(WorkspaceTransaction::class)->run($a, function () {
        app(Audit::class)->record(AuditAction::AccessRoleChanged, ['role' => 'admin']);
        app(Audit::class)->recordSecurityEvent(AuditAction::AccessAdminDenied, ['reason' => 'not_admin']);
        throw new RuntimeException('denied');
    }))->toThrow(RuntimeException::class);

    $rows = Cluster::rows(Cluster::superuser(), 'select action, security, workspace_id from audit_events');
    expect($rows)->toBe([['action' => 'access.admin.denied', 'security' => true, 'workspace_id' => $a]]);
});

it('refuses UPDATE and DELETE on audit_events to app, allowing INSERT and SELECT', function () {
    $a = Cluster::workspace('A');
    Cluster::seedTenantRow('audit_events', $a);

    foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
        expect(fn () => Cluster::inWorkspace($app, $a, fn ($pdo) => $pdo->exec("update audit_events set action = 'x'")))->toThrow(PDOException::class, 'permission denied');
        expect(fn () => Cluster::inWorkspace($app, $a, fn ($pdo) => $pdo->exec('delete from audit_events')))->toThrow(PDOException::class, 'permission denied');
        expect(Cluster::inWorkspace($app, $a, fn ($pdo) => count(Cluster::rows($pdo, 'select id from audit_events'))))->toBe(1);
    }

    app(WorkspaceTransaction::class)->run($a, fn () => app(Audit::class)->record(AuditAction::AccessRoleChanged));
    expect(counts()[0])->toBe(2);
});

it('lets app read neither Workspace\'s audit or outbox rows across Workspaces', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedTenantRow('audit_events', $b);
    Cluster::seedOutbox($b);

    expect(Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, 'select id from audit_events')))->toBe([])
        ->and(Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, 'select id from outbox_events')))->toBe([]);
});

it('rejects event data that is not an integer, boolean, null, UUID or short slug', function (mixed $value) {
    $a = Cluster::workspace('A');

    app(WorkspaceTransaction::class)->run($a, function () use ($value) {
        expect(fn () => app(Outbox::class)->emit(AuditAction::AccessRoleChanged, 'membership:1', ['field' => $value]))->toThrow(InvalidArgumentException::class);
    });

    expect(counts())->toBe([0, 0]);
})->with([
    'free text' => ['Alice is now an admin'],
    'object' => [new stdClass],
    'array' => [['a' => 1]],
    'float' => [1.5],
    'email' => ['a@example.test'],
    'long slug' => [str_repeat('a', 80)],
]);

it('numbers subject_seq 1..n with no gaps or duplicates under concurrent emitters', function () {
    $a = Cluster::workspace('A');
    $subject = 'membership:'.Str::uuid7();
    $script = dirname(__DIR__).'/Database/Support/emit.php';

    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $process = proc_open([PHP_BINARY, $script, $a, $subject, '5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
        $processes[] = [$process, $pipes];
    }

    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        expect(proc_close($process))->toBe(0, $output);
    }

    $seqs = array_map('intval', array_column(Cluster::rows(Cluster::superuser(), 'select subject_seq from outbox_events where subject = ? order by subject_seq', [$subject]), 'subject_seq'));
    expect($seqs)->toBe(range(1, 30));

    // Another subject starts at 1.
    $first = app(WorkspaceTransaction::class)->run($a, fn () => app(Outbox::class)->emit(AuditAction::AccessRoleChanged, 'membership:2'));
    expect($first->subjectSeq)->toBe(1);
});

it('returns without throwing, and logs IDs only, when a security event cannot be stored', function () {
    Log::spy();
    $missing = (string) Str::uuid7();

    expect(app(Audit::class)->recordSecurityEvent(AuditAction::AccessAdminDenied, ['reason' => 'not_admin'], $missing))->toBeNull();
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'audit.security.failed' && $context['workspace_id'] === $missing && ! isset($context['values']))->once();
    expect(counts())->toBe([0, 0]);

    // An unreachable audit connection is handled the same way.
    $a = Cluster::workspace('A');
    config(['database.connections.security_audit.port' => 1]);
    DB::purge('security_audit');

    expect(app(Audit::class)->recordSecurityEvent(AuditAction::AccessAdminDenied, [], $a))->toBeNull();
    config(['database.connections.security_audit.port' => env('DB_SECURITY_PORT')]);
    DB::purge('security_audit');
});

it('still rejects an unknown action in recordSecurityEvent', function () {
    $a = Cluster::workspace('A');

    expect(fn () => app(Audit::class)->recordSecurityEvent('nope.nope.nope', [], $a))->toThrow(InvalidArgumentException::class);
});

it('hashes the client IP and user agent of a web request, and stores null without one', function () {
    $a = Cluster::workspace('A');
    $transaction = app(WorkspaceTransaction::class);

    $transaction->run($a, fn () => app(Audit::class)->record(AuditAction::AccessRoleChanged));
    $none = Cluster::rows(Cluster::superuser(), 'select ip_hash, user_agent_hash from audit_events')[0];
    expect($none)->toBe(['ip_hash' => null, 'user_agent_hash' => null]);

    $request = Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'SecretAgent/9.9']);
    app()->instance('request', $request);
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $console->setValue(app(), false);

    try {
        $transaction->run($a, fn () => app(Audit::class)->record(AuditAction::AccessRoleChanged));
    } finally {
        $console->setValue(app(), true);
    }

    $row = Cluster::rows(Cluster::superuser(), 'select ip_hash, user_agent_hash from audit_events where ip_hash is not null')[0];
    expect($row['ip_hash'])->toStartWith('hmac-sha256:')->not->toContain('203.0.113.9')
        ->and($row['user_agent_hash'])->toStartWith('hmac-sha256:')->not->toContain('SecretAgent');
});

it('applies the real registry allowlists to identity and access actions', function () {
    $registry = app(AuditSerializers::class);

    $identity = $registry->serialize(AuditAction::IdentitySigninFailed, ['email' => 'a@example.test', 'reason' => 'bad_password', 'user_id' => 5, 'password' => 'x']);
    expect(array_keys($identity))->toBe(['user_id', 'reason', 'email'])
        ->and($identity['email'])->toStartWith('hmac-sha256:')->and($identity['reason'])->toBe('bad_password');

    $access = $registry->serialize(AuditAction::AccessAttributeChanged, ['attribute_key' => 'region', 'attribute_value' => 'EMEA', 'token' => 't']);
    expect($access)->toHaveCount(2)->and($access['attribute_key'])->toBe('region')->and($access['attribute_value'])->toStartWith('hmac-sha256:');
});
