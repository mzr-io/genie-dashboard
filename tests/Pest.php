<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DatabaseTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(DatabaseTestCase::class)->in('Database');
// The Admin route architecture test reads the booted router.
pest()->extend(TestCase::class)->in('Architecture/AdminRoutesTest.php', 'Architecture/InvitationMailOrderTest.php');
