<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Fail-closed guard for destructive automated test operations.
 *
 * Two independent checks protect MySQL targets:
 *
 *  1. an explicit denylist of protected schemas (the development database and
 *     every prior-stage acceptance database), and
 *  2. a strict allowlist pattern that only accepts a clearly disposable
 *     `special_delicacies_*_test` schema.
 *
 * Both are required: the prior-stage `*_test` schemas match the allowlist
 * pattern, so the denylist is what stops them. A database whose identity cannot
 * be established is rejected rather than assumed safe.
 *
 * This class is intentionally dependency-free so it can be unit-tested without
 * booting the framework or opening a database connection.
 */
final class TestDatabaseGuard
{
    /**
     * Databases that must never be touched by the automated test suite.
     */
    public const PROTECTED_DATABASES = [
        'special_delicacies',
        'special_delicacies_db',
        'special_delicacies_stage4a_test',
        'special_delicacies_stage4a_acceptance_test',
        'special_delicacies_stage4b_test',
    ];

    /**
     * Refuse to continue unless the resolved connection/database pair is a
     * disposable test target. Throws before any migration, seed or refresh can
     * run.
     */
    public static function assertSafe(string $connection, ?string $database): void
    {
        $connection = strtolower(trim($connection));
        $database = trim((string) $database);

        // Fail closed: an unresolved database identity is never assumed safe.
        if ($database === '') {
            throw new RuntimeException('Refusing to run tests: the test database identity could not be established.');
        }

        if ($connection === 'sqlite') {
            if ($database === ':memory:') {
                return;
            }

            $basename = strtolower(basename($database));
            $name = preg_replace('/\.sqlite$/', '', $basename) ?? $basename;
            if (str_ends_with($basename, '_test.sqlite') && ! self::isProtected($name)) {
                return;
            }

            throw new RuntimeException("Refusing to run destructive tests against SQLite database [{$database}].");
        }

        if (self::isDisposableMysqlDatabase($database)) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run tests against connection [{$connection}] database [{$database}]. "
            .'Automated tests must use in-memory SQLite or an explicitly disposable MySQL test database.'
        );
    }

    /** True when the name is one of the protected databases (case-insensitive). */
    public static function isProtected(string $database): bool
    {
        return in_array(strtolower(trim($database)), self::PROTECTED_DATABASES, true);
    }

    /**
     * A disposable MySQL schema must match the allowlist pattern AND must not be
     * one of the protected databases.
     */
    public static function isDisposableMysqlDatabase(string $database): bool
    {
        $database = strtolower(trim($database));

        if ($database === '' || self::isProtected($database)) {
            return false;
        }

        return (bool) preg_match('/^special_delicacies_[a-z0-9_]+_test$/', $database);
    }
}
