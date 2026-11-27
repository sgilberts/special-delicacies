<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fail fast before any destructive test setup (RefreshDatabase, migrations
     * or seeds) can touch a development or protected database. phpunit.xml
     * already forces an in-memory SQLite connection; this guard also protects
     * an explicit MySQL test run.
     */
    protected function setUpTraits()
    {
        $this->assertSafeTestDatabase();

        return parent::setUpTraits();
    }

    private function assertSafeTestDatabase(): void
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Refusing to run tests outside the testing environment.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection === 'sqlite') {
            if ($database !== ':memory:' && ! str_ends_with($database, '_test.sqlite')) {
                throw new RuntimeException("Refusing to run destructive tests against SQLite database '{$database}'.");
            }

            return;
        }

        $protected = ['special_delicacies', 'special_delicacies_db'];
        if (! str_ends_with($database, '_test') || in_array(strtolower($database), $protected, true)) {
            throw new RuntimeException("Refusing to run destructive tests against non-disposable database '{$database}'.");
        }
    }
}
