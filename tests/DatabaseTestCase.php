<?php

namespace Tests;

use Tests\Database\Support\Cluster;

/**
 * Base for the Database suite: boots the application against the real PostgreSQL, migrates it once
 * per run as role `migrator`, and empties it before each test. Never skips: an unreachable
 * database makes setUp throw, which fails the test.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        // Before anything touches a database: never migrate or truncate a database that is not the test one.
        Cluster::guard();

        parent::setUp();

        Cluster::migrateOnce();
        Cluster::truncate();
    }
}
