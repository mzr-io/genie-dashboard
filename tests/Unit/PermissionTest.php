<?php

use App\Modules\Access\Contracts\Permission;

it('is the closed set of nine Admin permissions', function () {
    expect(Permission::values())->toEqualCanonicalizing([
        'data_sources.manage', 'blocks.edit', 'blocks.publish', 'templates.manage', 'users.manage',
        'settings.manage', 'audit.view', 'data.preview_as_user', 'access.manage',
    ]);
});

it('matches the permissions the membership_permissions CHECK constraint allows', function () {
    $migration = (string) file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_10_06_120001_create_membership_permissions_table.php');

    foreach (Permission::values() as $value) {
        expect($migration)->toContain("'{$value}'");
    }

    preg_match_all("/'([a-z_]+\.[a-z_]+)'/", substr($migration, 0, (int) strpos($migration, 'public function up')), $found);
    expect($found[1])->toEqualCanonicalizing(Permission::values());
});
