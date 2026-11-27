<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * Regression coverage for the destructive-test database guard. These are pure
 * unit tests: they never boot the framework and never open a database
 * connection, so they are safe to run even where a protected database exists.
 */
class TestDatabaseGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedDatabases(): array
    {
        return [
            'development database' => ['special_delicacies'],
            'development database (db suffix)' => ['special_delicacies_db'],
            'stage 4a test' => ['special_delicacies_stage4a_test'],
            'stage 4a acceptance' => ['special_delicacies_stage4a_acceptance_test'],
            'stage 4b test' => ['special_delicacies_stage4b_test'],
        ];
    }

    #[DataProvider('protectedDatabases')]
    public function test_each_protected_database_is_rejected_for_mysql(string $database): void
    {
        $this->assertTrue(TestDatabaseGuard::isProtected($database));
        $this->assertFalse(TestDatabaseGuard::isDisposableMysqlDatabase($database));

        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe('mysql', $database);
    }

    #[DataProvider('protectedDatabases')]
    public function test_each_protected_database_is_rejected_case_insensitively(string $database): void
    {
        $upper = strtoupper($database);
        $this->assertFalse(TestDatabaseGuard::isDisposableMysqlDatabase($upper));

        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe('mysql', $upper);
    }

    public function test_protected_databases_cover_the_required_list(): void
    {
        $required = [
            'special_delicacies_db',
            'special_delicacies_stage4a_test',
            'special_delicacies_stage4a_acceptance_test',
            'special_delicacies_stage4b_test',
        ];

        foreach ($required as $name) {
            $this->assertContains($name, TestDatabaseGuard::PROTECTED_DATABASES, "{$name} must be protected.");
        }
    }

    public function test_disposable_mysql_database_is_accepted(): void
    {
        $this->assertTrue(TestDatabaseGuard::isDisposableMysqlDatabase('special_delicacies_guard_probe_test'));

        // Positive path: must not throw.
        TestDatabaseGuard::assertSafe('mysql', 'special_delicacies_guard_probe_test');
        $this->addToAssertionCount(1);
    }

    public function test_in_memory_sqlite_is_accepted(): void
    {
        TestDatabaseGuard::assertSafe('sqlite', ':memory:');
        $this->addToAssertionCount(1);
    }

    public function test_disposable_sqlite_file_is_accepted(): void
    {
        TestDatabaseGuard::assertSafe('sqlite', '/tmp/guard_probe_test.sqlite');
        $this->addToAssertionCount(1);
    }

    public function test_protected_sqlite_file_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe('sqlite', '/tmp/special_delicacies_stage4b_test.sqlite');
    }

    public function test_non_disposable_mysql_database_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe('mysql', 'production');
    }

    public function test_unresolved_database_identity_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe('mysql', '');
    }

    public function test_null_database_identity_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe('mysql', null);
    }
}
