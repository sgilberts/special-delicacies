<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Databases that must never be touched by the automated test suite.
     *
     * The sandbox/development and prior-stage acceptance databases are listed
     * explicitly so a misconfigured environment fails fast instead of running
     * RefreshDatabase (migrate:fresh) against real financial data.
     */
    private const PROTECTED_DATABASES = [
        'special_delicacies',
        'special_delicacies_db',
        'special_delicacies_stage4a_test',
        'special_delicacies_stage4a_acceptance_test',
        'special_delicacies_stage4b_test',
    ];

    /**
     * Force the test environment before the framework boots.
     *
     * phpunit.xml declares these values, but PHPUnit does not override variables
     * that already exist in the process environment. A container that exports
     * DB_CONNECTION=mysql would therefore let RefreshDatabase reset the
     * development database. Setting all three environment stores guarantees the
     * suite always runs against in-memory SQLite with non-delivering mail and a
     * synchronous, non-broadcasting stack.
     *
     * MySQL compatibility runs are opt-in through BASE44_MYSQL_TEST_DATABASE and
     * are only accepted for a disposable `special_delicacies_*_test` database
     * that is not one of the protected names.
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
            if (! $this->isDisposableMysqlDatabase($mysqlTestDatabase)) {
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
     * Fail fast when the resolved connection is not a disposable target.
     */
    private function assertSafeTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection === 'sqlite' && $database === ':memory:') return;
        if ($connection === 'mysql' && $this->isDisposableMysqlDatabase($database)) return;

        throw new RuntimeException(
            "Refusing to run tests against connection [{$connection}] database [{$database}]. "
            .'Automated tests must use in-memory SQLite or an explicitly disposable MySQL test database.'
        );
    }

    private function isDisposableMysqlDatabase(string $database): bool
    {
        if (in_array(strtolower($database), self::PROTECTED_DATABASES, true)) return false;

        return (bool) preg_match('/^special_delicacies_[a-z0-9_]+_test$/', strtolower($database));
    }
}
