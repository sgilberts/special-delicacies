# Stage 4B — MySQL 8.4.11 Verification

**Document type:** Database compatibility verification
**Date:** 2026-11-27
**Branch:** `stage-4b-remediation` (base: PR #1 head `6d29dd7`)

---

## 1. Target and why a dedicated instance was used

The approved target is **MySQL 8.4.11**. The development compose service uses `mysql:8.4`,
which resolves to **8.4.13** in this sandbox, so a dedicated, isolated **8.4.11** instance was
used for this verification. The project's approved database architecture and target version
were not changed.

- Server version verified: `SELECT VERSION()` → **8.4.11**.
- Instance: disposable container `sd-mysql8411` (`mysql:8.4.11`) on the compose network
  `app_default`, root access with an empty password, database
  `special_delicacies_guard_probe_test` (explicitly disposable).
- No protected database was used. The protected schemas were never created on this instance.

---

## 2. Command

The suite was executed inside the application PHP container with the opt-in MySQL path
enabled through the test guard:

```
BASE44_MYSQL_TEST_DATABASE=special_delicacies_guard_probe_test \
DB_HOST=sd-mysql8411 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD= \
php vendor/bin/phpunit --no-coverage \
  tests/Feature/Stage4bCreditAndSecurityTest.php \
  tests/Feature/Stage4bCustomerIdentityGatingTest.php \
  tests/Feature/Stage4bCustomerOnlineDepositTest.php \
  tests/Feature/Stage4bRemediationTest.php \
  tests/Feature/Stage4bStaffRecordedDepositTest.php \
  tests/Feature/Stage4bDatabaseGuardWiringTest.php \
  tests/Unit/TestDatabaseGuardTest.php \
  tests/Feature/PaymentAllocationTest.php \
  tests/Feature/CommercialDocumentsTest.php \
  tests/Feature/FinancialManagementTest.php \
  tests/Feature/CustomerIdentityTest.php \
  tests/Feature/RbacTest.php \
  tests/Feature/SecurityAndApiTest.php \
  tests/Feature/ForeignKeyRestrictionTest.php \
  tests/Feature/MigrationRollbackVerificationTest.php
```

`RefreshDatabase` runs `migrate:fresh` once against the disposable schema, then wraps each
test in a transaction.

---

## 3. Results

```
OK (140 tests, 823 assertions)
```

Schema after the run: **49 tables**, including:

```
account_security_challenges
customer_credit_ledger_entries
payment_allocations
payment_receipt_intents
payment_transactions
staff_deposit_records
```

Areas covered by the executed suites:

| Area | Evidence |
| --- | --- |
| Migrations (including `staff_deposit_records`) | 49 tables created on 8.4.11 |
| Deposit lifecycle (pending/succeeded/failed/cancelled) | `Stage4bStaffRecordedDepositTest`, `Stage4bCustomerOnlineDepositTest` |
| Credit calculations, designation/application | `Stage4bCreditAndSecurityTest`, `Stage4bRemediationTest` |
| Verification and identity gating | `Stage4bCustomerIdentityGatingTest`, `Stage4bCreditAndSecurityTest` |
| Stage 4A regression | `PaymentAllocationTest`, `CommercialDocumentsTest`, `FinancialManagementTest` |
| Guard behaviour | `TestDatabaseGuardTest`, `Stage4bDatabaseGuardWiringTest` |
| Unique constraints / foreign keys | `ForeignKeyRestrictionTest`, `MigrationRollbackVerificationTest` |
| Transactions / locking | `lockForUpdate` paths in deposits, allocation and challenges executed under InnoDB |

**Concurrency note:** the locking code paths (`lockForUpdate`, nested
`DB::transaction(..., 3)`) executed without error under InnoDB, and the tests wrap each case
in a transaction. True multi-connection concurrency (two simultaneous writers) was not
exercised by a parallel harness; the locking logic is asserted by code inspection and by the
serialized idempotency tests, not by a stress test.

---

## 4. Guard refusal on 8.4.11

Each protected name was supplied as the opt-in MySQL database against the 8.4.11 instance.
The guard refused before connecting or migrating:

```
special_delicacies_db                    -> Refusing MySQL test run against non-disposable database
special_delicacies_stage4a_test          -> Refusing MySQL test run against non-disposable database
special_delicacies_stage4a_acceptance_test -> Refusing MySQL test run against non-disposable database
special_delicacies_stage4b_test          -> Refusing MySQL test run against non-disposable database
```

`SHOW DATABASES` afterwards listed only `special_delicacies_guard_probe_test` plus the MySQL
system schemas — no protected database was created or modified.

---

## 5. Disposal

The disposable `sd-mysql8411` instance and its database were removed after verification. The
sandbox's development database was left at its seeded baseline (four seeded staff accounts,
no financial records).

---

## 6. Conclusion

Stage 4B migrations, deposit lifecycle, credit calculations, verification/identity gating,
Stage 4A regression, guard behaviour, and FK/migration behaviour all pass on **MySQL 8.4.11**
(140 tests, 823 assertions). No protected database was used.
