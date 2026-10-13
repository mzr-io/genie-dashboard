<?php

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 3.1 against the real PostgreSQL: the registry list for an Admin with blocks.edit, and the audited denial without it.
const BT_HEADERS = ['Referer' => 'http://localhost:8000'];

beforeEach(fn () => $this->withoutVite());

/** Signs in an Admin-area membership holding `$permissions`. */
function btSignIn(array $permissions, string $area = 'admin'): void
{
    $workspace = Cluster::workspace('Acme');
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'admin', 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspace, $membership, $permission]);
    }

    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => $area]);
}

function btDenials(): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
}

it('lists kpi-card, kpi-chart and line-area v1 for an Admin with blocks.edit', function () {
    btSignIn(['blocks.edit']);

    $response = $this->getJson('/api/v1/block-types', BT_HEADERS);

    $response->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.key', 'kpi-card')
        ->assertJsonPath('data.0.contract_version', 1)
        ->assertJsonPath('data.0.size.default_width', 3)
        ->assertJsonPath('data.0.size.default_height', 'small')
        ->assertJsonPath('data.0.default_aggregations.headline_value', 'sum');
    expect($response->json('data.0.slots'))->toHaveCount(10)
        ->and($response->json('data.1.key'))->toBe('kpi-chart')
        ->and($response->json('data.2.key'))->toBe('line-area')
        ->and(array_column($response->json('data.2.slots'), 'key'))->toContain('x', 'series_1', 'series_6', 'series_label_1', 'highlight_x', 'target_value')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers 403 with an audit row to an Admin without blocks.edit', function () {
    btSignIn([]);
    $before = btDenials();

    $this->getJson('/api/v1/block-types', BT_HEADERS)->assertForbidden()->assertJsonPath('error.code', 'access.not_authorized');

    expect(btDenials())->toBe($before + 1);
});

it('answers 403 with an audit row to a User-area session', function () {
    btSignIn(['blocks.edit'], 'user');
    $before = btDenials();

    $this->getJson('/api/v1/block-types', BT_HEADERS)->assertForbidden();

    expect(btDenials())->toBe($before + 1);
});
