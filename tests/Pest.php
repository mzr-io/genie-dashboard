<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DatabaseTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Security');
pest()->extend(DatabaseTestCase::class)->in('Database');
// The Admin route architecture test reads the booted router.
pest()->extend(TestCase::class)->in('Architecture/AdminRoutesTest.php', 'Architecture/InvitationMailOrderTest.php');

// The security suite (Story 1.25): `composer test:security` and `composer ci:check`. Existing tests are
// tagged by file, never duplicated; tests/Security/CoverageManifestTest.php checks the listed ones are here.
pest()->group('security')->in(
    'Security',
    'Database/RowLevelSecurityTest.php',
    'Database/PoolingTest.php',
    'Database/RolePrivilegesTest.php',
    'Database/SignInTest.php',
    'Database/WorkspaceSwitchTest.php',
    'Database/WorkspaceProvisioningTest.php',
    'Database/InviteUsersTest.php',
    'Database/MemberAccessTest.php',
    'Database/MemberStatusTest.php',
    'Database/UserAttributesTest.php',
    'Database/PasswordResetTest.php',
    'Database/AdminAccessTest.php',
    'Database/AuditTest.php',
    'Database/WorkspaceJobTest.php',
    'Database/WorkspaceTransactionTest.php',
    'Database/TenantLeakCoverageTest.php',
    'Feature/Auth/SignInTest.php',
    'Feature/Auth/PasswordResetFlowTest.php',
    'Feature/Queue/SignedJobTest.php',
    'Unit/JobSignerTest.php',
    'Unit/EgressGuardTest.php',
    'Unit/BlockedAddressClassTest.php',
    'Database/EgressGrantsTest.php',
    'Database/EgressTransportTest.php',
    'Database/DataSourceSecretsTest.php',
    'Unit/LocalSecretVaultTest.php',
    'Unit/FetchRequestTest.php',
    'Unit/RejectsSecretValuesTest.php',
    'Unit/DirectFetchTransportTest.php',
    'Database/ConnectionTestTest.php',
    'Database/OAuthClientCredentialsTest.php',
    'Unit/OAuthClientCredentialsTest.php',
    'Database/OperationsTest.php',
    'Database/EditLockTest.php',
    'Database/PartitionsTest.php',
    'Database/ScheduledFetchTest.php',
    'Unit/FetchKeyResolverTest.php',
);
