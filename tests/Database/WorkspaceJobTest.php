<?php

use App\Platform\Tenancy\WorkspaceMismatchException;
use App\Platform\Tenancy\WorkspaceScopedJob;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Queue\JobSigner;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Database\Fixtures\TenantJob;
use Tests\Database\Support\Cluster;

beforeEach(fn () => TenantJob::$seen = null);

it('re-enters the Workspace context and re-reads its IDs under RLS', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $rowA = Cluster::seedTenantRow('workspace_memberships', $a);
    Cluster::seedTenantRow('workspace_memberships', $b);

    dispatch(new TenantJob($a, [$rowA]));

    expect(TenantJob::$seen)->toBe([$rowA]);
});

it('fails hard and logs the security event when a job holds an ID from another Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedTenantRow('workspace_memberships', $a);
    $rowB = Cluster::seedTenantRow('workspace_memberships', $b);

    Log::spy();

    expect(fn () => dispatch(new TenantJob($a, [$rowB])))->toThrow(WorkspaceMismatchException::class);

    expect(TenantJob::$seen)->toBeNull();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($a, $rowB): bool {
        return $message === 'security.tenancy.workspace_mismatch'
            && $context['workspace_id'] === $a
            && $context['table'] === 'workspace_memberships'
            && $context['ids'] === [$rowB]
            && array_keys($context) === ['security_event', 'workspace_id', 'table', 'ids', 'reason'];
    });
});

it('marks a queued job as failed so it is not retried', function () {
    $a = Cluster::workspace('A');
    $rowB = Cluster::seedTenantRow('workspace_memberships', Cluster::workspace('B'));

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(WorkspaceMismatchException::class));

    $job = new TenantJob($a, [$rowB]);
    $job->job = $queueJob;

    expect(fn () => app(WorkspaceTransaction::class)->runJob($job, fn () => null))->toThrow(WorkspaceMismatchException::class);
});

it('rejects an ID that is not a UUID or a job without a Workspace-scoped contract', function () {
    $a = Cluster::workspace('A');

    expect(fn () => app(WorkspaceTransaction::class)->runJob(new TenantJob($a, ['1 or 1=1']), fn () => null))
        ->toThrow(WorkspaceMismatchException::class);

    expect(fn () => app(WorkspaceTransaction::class)->runJob(new stdClass, fn () => null))->toThrow(LogicException::class);
});

it('treats a malformed Workspace ID as a mismatch: logged without data, failed without retry', function () {
    Log::spy();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(WorkspaceMismatchException::class));

    $job = new TenantJob("not-a-uuid'; select secret --", []);
    $job->job = $queueJob;
    $ran = false;

    expect(fn () => app(WorkspaceTransaction::class)->runJob($job, function () use (&$ran) {
        $ran = true;
    }))->toThrow(WorkspaceMismatchException::class);

    expect($ran)->toBeFalse();

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'security.tenancy.workspace_mismatch'
        && $context['workspace_id'] === null
        && $context['ids'] === []
        && ! str_contains(json_encode($context), 'secret'));
});

it('treats an unreadable Workspace ID as a mismatch too', function () {
    Log::spy();

    $job = new class implements WorkspaceScopedJob
    {
        public function workspaceId(): string
        {
            throw new Error('typed property must not be accessed before initialization');
        }

        public function referencedIds(): array
        {
            return [];
        }
    };

    expect(fn () => app(WorkspaceTransaction::class)->runJob($job, fn () => null))->toThrow(WorkspaceMismatchException::class);
    Log::shouldHaveReceived('error')->once();
});

it('carries workspace_id in the serialised command, so the job signature covers it', function () {
    $a = (string) Str::uuid7();
    $b = (string) Str::uuid7();

    $payload = [
        'uuid' => 'f2a8b0c6-6a9f-4c8e-9e3c-1c1d5e7b8a90',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => TenantJob::class, 'command' => serialize(new TenantJob($a, []))],
    ];

    expect($payload['data']['command'])->toContain($a);

    $signer = new JobSigner('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    $signed = $signer->sign($payload);
    expect($signer->verify($signed))->toBeTrue();

    $tampered = $signed;
    $tampered['data']['command'] = str_replace($a, $b, $signed['data']['command']);
    expect($signer->verify($tampered))->toBeFalse();
});

it('is a WorkspaceScopedJob', function () {
    expect(new TenantJob((string) Str::uuid7(), []))->toBeInstanceOf(WorkspaceScopedJob::class);
});
