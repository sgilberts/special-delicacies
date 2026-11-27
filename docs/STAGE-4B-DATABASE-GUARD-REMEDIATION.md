# Stage 4B — Database Safety Guard Remediation

**Document type:** Defect remediation and evidence
**Date:** 2026-11-27
**Branch:** `stage-4b-remediation` (base: PR #1 head `6d29dd7`)

---

## 1. Defect

PR #1's `Tests\TestCase` guard only protected `special_delicacies` and
`special_delicacies_db` and otherwise accepted any MySQL database whose name ended in
`_test`. The three prior-stage schemas therefore satisfied the guard and would have been
treated as disposable:

- `special_delicacies_stage4a_test`
- `special_delicacies_stage4a_acceptance_test`
- `special_delicacies_stage4b_test`

The same weak guard exists on `main` (which has no guard at all in `Tests\TestCase`) and on
the `stage-4c-refunds-credit-notes` branch. The corrected guard exists on PR #2
(`refunds-credit-notes`). This defect is merge-blocking because `RefreshDatabase` runs
`migrate:fresh` against the configured database.

---

## 2. Required protection list

All of the following must be refused by every destructive test path:

- `special_delicacies`
- `special_delicacies_db`
- `special_delicacies_stage4a_test`
- `special_delicacies_stage4a_acceptance_test`
- `special_delicacies_stage4b_test`

---

## 3. Remediation

Reconciled the corrected PR #2 approach into the isolated Stage 4B remediation branch, then
hardened it so it does not rely on a single hardcoded check.

### 3.1 `backend/tests/Support/TestDatabaseGuard.php` (new)

Dependency-free, unit-testable guard applying **two independent checks** for MySQL targets:

1. **Denylist** — `PROTECTED_DATABASES` contains the development database and all prior
   stage databases above (compared case-insensitively).
2. **Allowlist pattern** — a MySQL schema is disposable only if it matches
   `^special_delicacies_[a-z0-9_]+_test$` **and** is not in the denylist. The prior-stage
   `*_test` schemas match the pattern, so the denylist is what stops them; the pattern stops
   arbitrary or production names.

Additional fail-closed behaviour:

- An empty/unresolved database identity throws — it is never assumed safe.
- SQLite is accepted only for `:memory:` or a `*_test.sqlite` file whose basename is not a
  protected database.

### 3.2 `backend/tests/TestCase.php` (rewritten)

- Overrides `createApplication()` to force the isolated test environment
  (`APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`,
  `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array`) before the framework boots, then validates the resolved database.
- An opt-in MySQL compatibility run is accepted only through
  `BASE44_MYSQL_TEST_DATABASE`, and only when `TestDatabaseGuard::isDisposableMysqlDatabase()`
  accepts it.
- Overrides `setUpTraits()` to re-assert the guard immediately before `RefreshDatabase`,
  migrations or seeds can run.

### 3.3 Test-execution paths covered

- Every Feature test extends `Tests\TestCase`, so both `createApplication()` and
  `setUpTraits()` run the guard. `Stage4bDatabaseGuardWiringTest` asserts this by scanning
  every `tests/Feature/*Test.php` and confirming it subclasses the guarded base class, and
  by reflecting that both methods are declared on `Tests\TestCase`.
- Pure unit tests (`tests/Unit/ExampleTest.php`, `tests/Unit/TestDatabaseGuardTest.php`)
  extend PHPUnit's base class and never open a database connection.

---

## 4. Regression tests

`backend/tests/Unit/TestDatabaseGuardTest.php` (pure unit, no database connection):

- Each of the five protected names is rejected for MySQL, including case-insensitively
  (data-provider driven).
- The required four names are asserted to be present in `PROTECTED_DATABASES`.
- Positive path: `special_delicacies_guard_probe_test` is accepted.
- `:memory:` and a disposable `*_test.sqlite` file are accepted.
- A protected SQLite basename, an unrelated MySQL name (`production`), and empty/null
  identities are rejected.

`backend/tests/Feature/Stage4bDatabaseGuardWiringTest.php`:

- Guard methods are declared on `Tests\TestCase`.
- Every Feature test extends `Tests\TestCase`.
- The resolved suite connection is an accepted disposable target.

---

## 5. Evidence

### 5.1 Unit + wiring results (in-memory SQLite)

```
Tests\Unit\TestDatabaseGuardTest .......... 18 passed
Tests\Feature\Stage4bDatabaseGuardWiringTest ... OK
Stage 4B subset: OK (79 tests, 510 assertions)
```

### 5.2 Live refusal against an isolated MySQL 8.4.11 instance

Each protected name was passed as `BASE44_MYSQL_TEST_DATABASE` against a disposable 8.4.11
instance where the protected schemas do not exist. The guard refused before any connection
or migration:

```
### special_delicacies_db
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_db].
### special_delicacies_stage4a_test
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_stage4a_test].
### special_delicacies_stage4a_acceptance_test
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_stage4a_acceptance_test].
### special_delicacies_stage4b_test
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_stage4b_test].
```

`SHOW DATABASES` on that instance afterwards listed only
`special_delicacies_guard_probe_test` — no protected database was created or touched.

### 5.3 Positive disposable-database test

The Stage 4B / Stage 4A / identity / security / guard / FK / migration suites ran against
the disposable `special_delicacies_guard_probe_test` on MySQL 8.4.11:
**140 tests, 823 assertions — OK.**

---

## 6. Conclusion

The merge-blocking database-safety defect is remediated on the remediation branch. The guard
refuses every protected database, does not rely on a single hardcoded name, fails closed on
an unresolved identity, and is proven by both unit regression tests and a live refusal test.
The corrected guard must be present in the Stage 4B head before formal acceptance.
