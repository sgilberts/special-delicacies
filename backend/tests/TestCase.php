<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Force the isolated test environment before the framework boots, then
     * refuse to continue unless the resolved database is disposable.
     *
     * PHPUnit does not override variables that already exist in the process
     * environment, so a container that exports DB_CONNECTION=mysql could
     * otherwise let RefreshDatabase reset the development database. An opt-in
     * MySQL compatibility run is accepted only for a disposable
     * `special_delicacies_*_test` schema that is not one of the protected
     * databases.
     */
    public function createApplication()
    {
        $mysqlTestDatabase = (string) (getenv('BASE44_MYSQL_TEST_DATABASE') ?: '');
        $forced = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'BROADCAST_CONNECTION' => 'null',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];

        if ($mysqlTestDatabase !== '') {
            if (! TestDatabaseGuard::isDisposableMysqlDatabase($mysqlTestDatabase)) {
                throw new RuntimeException("Refusing MySQL test run against non-disposable database [{$mysqlTestDatabase}].");
            }
            $forced['DB_CONNECTION'] = 'mysql';
            $forced['DB_DATABASE'] = $mysqlTestDatabase;
        }

        foreach ($forced as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        $app = parent::createApplication();

        $this->assertSafeTestDatabase();

        return $app;
    }

    /**
     * Second guard immediately before any destructive trait setup
     * (RefreshDatabase, migrations or seeds) can run.
     */
    protected function setUpTraits()
    {
        $this->assertSafeTestDatabase();

        return parent::setUpTraits();
    }

    private function assertSafeTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");

        TestDatabaseGuard::assertSafe($connection, is_string($database) ? $database : null);
    }
}
