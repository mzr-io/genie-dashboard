<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\UserMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Story 1.24 on the SQLite Feature suite: the per-request membership check answers a deactivated member's next request
// with 401 (JSON, API, Inertia) or a redirect to sign-in (page), drops the Workspace and area keys, fails closed when the
// memberships cannot be read and leaves a session without a Workspace alone. A passing request opens the Workspace
// transaction (PostgreSQL only), so an active member, the real reads and the sessions deletion run in the Database suite.
beforeEach(fn () => $this->withoutVite());

function activeMembershipLookup(string $workspaceId, ?string $status, ?Throwable $fails = null): void
{
    app()->instance(MembershipLookup::class, new class($workspaceId, $status, $fails) implements MembershipLookup
    {
        public function __construct(private string $workspaceId, private ?string $status, private ?Throwable $fails) {}

        public function forUser(int $userId): array
        {
            throw_if($this->fails);

            return $this->status === null ? [] : [new UserMembership('m1', $this->workspaceId, 'Acme', null, 'active', 'user', $this->status, null)];
        }
    });
}

function activeMembershipSession(): string
{
    $workspaceId = (string) Str::uuid7();
    test()->actingAs(User::factory()->create())->withSession(['workspace_id' => $workspaceId, 'area' => 'user']);

    return $workspaceId;
}

it('answers a deactivated member\'s page load with a redirect to sign-in and drops the keys', function () {
    activeMembershipLookup(activeMembershipSession(), 'deactivated');

    $this->get(route('overview'))->assertRedirect(route('login'));

    expect(session('workspace_id'))->toBeNull()->and(session('area'))->toBeNull();
});

it('answers a deactivated member\'s JSON, API and Inertia requests with 401', function () {
    $workspaceId = activeMembershipSession();
    activeMembershipLookup($workspaceId, 'deactivated');

    $this->getJson('/api/v1/session', ['Referer' => 'http://localhost:8000'])->assertUnauthorized()
        ->assertJsonPath('error.code', 'platform.unauthenticated');
    expect(session('workspace_id'))->toBeNull();

    activeMembershipLookup(activeMembershipSession(), 'deactivated');
    $this->get(route('overview'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ])->assertUnauthorized();
});

it('leaves a session without a Workspace alone', function () {
    $this->actingAs(User::factory()->create());
    activeMembershipLookup((string) Str::uuid7(), 'deactivated');

    $this->get(route('overview'))->assertOk();
});

it('answers 503 and keeps the session when the memberships cannot be read', function () {
    Log::spy();
    $workspaceId = activeMembershipSession();
    activeMembershipLookup($workspaceId, 'active', new RuntimeException('down'));

    $this->get(route('overview'))->assertStatus(503);
    $this->getJson('/api/v1/session', ['Referer' => 'http://localhost:8000'])->assertStatus(503)
        ->assertJsonPath('error.code', 'platform.server_error');

    // Neither signed out nor regenerated: the person comes back when the read does.
    expect(session('workspace_id'))->toBe($workspaceId)->and(session('area'))->toBe('user');
    $this->assertAuthenticated('web');
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'access.membership.lookup_failed')->twice();
});

it('fails closed for a membership that is not active and for a Workspace the person has no membership in', function (?string $status) {
    activeMembershipLookup(activeMembershipSession(), $status);

    $this->get(route('overview'))->assertRedirect(route('login'));

    expect(session('workspace_id'))->toBeNull();
})->with(['suspended', 'removed', 'no membership' => null]);
