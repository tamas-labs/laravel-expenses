<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use TamasLabs\LaravelExpenses\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

// Migrated once per run, each test in a rolled-back transaction. Tests that
// run DDL (which commits implicitly in MySQL) live outside these directories.
pest()->use(RefreshDatabase::class)->in(
    'Feature/Auth',
    'Feature/Database',
    'Feature/Mapping',
    'Feature/Registry',
    'Feature/Rules',
    // Not the ConcurrencyTest: its connections have to see each other's commits.
    'Feature/Sync/PushTest.php',
    'Feature/Sync/PullTest.php',
    'Feature/Sync/LwwTest.php',
    'Feature/Sync/CursorTest.php',
    'Feature/Sync/ConvergenceTest.php',
);
