<?php

namespace Tests\Feature;

use ReflectionMethod;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

/**
 * Proves the fail-closed database guard is wired into the shared test base
 * class, so every Feature test inherits it on both application boot and
 * destructive trait setup. Booting this class exercises the guard itself; the
 * assertions below confirm the wiring and the protection list.
 */
class Stage4bDatabaseGuardWiringTest extends TestCase
{
    public function test_guard_is_declared_on_the_shared_base_test_case(): void
    {
        $this->assertSame(
            TestCase::class,
            (new ReflectionMethod(TestCase::class, 'createApplication'))->getDeclaringClass()->getName(),
            'createApplication must be overridden on Tests\\TestCase so the guard runs before the app boots.'
        );

        $this->assertSame(
            TestCase::class,
            (new ReflectionMethod(TestCase::class, 'setUpTraits'))->getDeclaringClass()->getName(),
            'setUpTraits must be overridden on Tests\\TestCase so the guard runs before destructive setup.'
        );
    }

    public function test_every_feature_test_extends_the_guarded_base_class(): void
    {
        $unguarded = [];

        foreach (glob(__DIR__.'/*Test.php') ?: [] as $file) {
            $class = 'Tests\\Feature\\'.basename($file, '.php');
            if (! class_exists($class)) {
                $unguarded[] = $class.' (could not be autoloaded)';

                continue;
            }
            if (! is_subclass_of($class, TestCase::class)) {
                $unguarded[] = $class;
            }
        }

        $this->assertSame([], $unguarded, 'Every Feature test must extend Tests\\TestCase to inherit the database guard.');
    }

    public function test_guard_is_using_a_disposable_database(): void
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");

        // The default suite runs on in-memory SQLite; the opt-in MySQL
        // compatibility run uses an explicitly disposable `*_test` schema.
        // Both are accepted by the guard; anything else must be rejected.
        $this->assertContains($connection, ['sqlite', 'mysql'], 'Unexpected test connection.');
        if ($connection === 'sqlite') {
            $this->assertSame(':memory:', $database, 'The default suite must use in-memory SQLite.');
        }

        TestDatabaseGuard::assertSafe($connection, is_string($database) ? $database : null);
        $this->addToAssertionCount(1);
    }
}
