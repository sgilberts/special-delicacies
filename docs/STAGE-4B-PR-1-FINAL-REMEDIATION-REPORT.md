# Stage 4B — PR #1 Final Remediation Integration Report

**Document type:** Integration assessment, verification evidence and readiness report
**Date:** 2026-11-27
**Repository:** `sgilberts/special-delicacies`
**Authorisation:** Owner-authorised controlled integration of the verified Stage 4B remediation
into PR #1. PR #1 and PR #2 must remain unmerged; Stage 4C acceptance and Stage 4D remain
unauthorised.

---

## 1. Purpose

This pass was authorised to (a) verify the true state of the repository and both pull
requests, (b) integrate the verified database-guard correction into the PR #1 source branch,
(c) review the nine-file PR #2 overlap, (d) confirm the seven Stage 4B reports are on the
correct branch and absent from PR #2, (e) repeat the full verification, and (f) record the
external production dependencies.

Sections 2–7 report what was verified. Section 8 records the one authorised action that
**could not be executed from this session** and the exact obstacle; section 12 states the
three safe routes to complete it.

---

## 2. Verified state before this pass

All values were read from Git and the GitHub API at the start of the pass; nothing was assumed
from earlier reports.

| Item | Value |
| --- | --- |
| Session branch / HEAD | `stage-4b-remediation` @ `89b36fc` |
| Working tree | Clean (`git status --porcelain` empty) |
| `stage-4b-remediation` vs `origin` | 0 ahead / 0 behind — the guard commit **is** pushed |
| PR #1 source branch / HEAD | `audit-deposits-credit` @ `6d29dd7` |
| PR #2 source branch / HEAD | `refunds-credit-notes` @ `ceb533b` |
| `main` | `95d6517` (unchanged; not touched) |
| PR #1 state | OPEN, base `main`, mergeable, 40 files, +4118 / −94 |
| PR #2 state | OPEN, base `main`, mergeable, 54 files, +8398 / −35 |

**Were the previous remediation commits committed and pushed?** Yes. Commit `89b36fc`
("Implement database-safety guard for Stage 4B remediation") is the tip of
`origin/stage-4b-remediation`, and the local branch is exactly in sync with it. This was
verified by comparing `git rev-parse HEAD` against `git rev-parse origin/stage-4b-remediation`
and by `git rev-list --left-right --count` (result `0  0`).

**Do the seven Stage 4B closure reports exist on the expected branch?** Yes — all seven are
present on `stage-4b-remediation` and indexed in `docs/README.md` (§6).

`stage-4b-remediation` is exactly `audit-deposits-credit` plus **one** commit:

```
A  backend/tests/Feature/Stage4bDatabaseGuardWiringTest.php
A  backend/tests/Support/TestDatabaseGuard.php
M  backend/tests/TestCase.php
A  backend/tests/Unit/TestDatabaseGuardTest.php
M  docs/README.md
A  docs/STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md
A  docs/STAGE-4B-DATABASE-GUARD-REMEDIATION.md
A  docs/STAGE-4B-FINAL-CLOSURE-REPORT.md
A  docs/STAGE-4B-INDEPENDENT-SECURITY-REVIEW.md
A  docs/STAGE-4B-MYSQL-8.4.11-VERIFICATION.md
A  docs/STAGE-4B-OUTSTANDING-SCOPE-RECONCILIATION.md
A  docs/STAGE-4B-PR-OVERLAP-AND-INTEGRATION-REPORT.md
```

No application code differs between PR #1's head and the remediation branch. The delta is
four test-harness files and eight documentation files.

---

## 3. Database guard integration

### 3.1 Required protection

Every destructive automated test path must refuse the five protected schemas:

| Protected database | Source of requirement |
| --- | --- |
| `special_delicacies` | development database (present on the sandbox server) |
| `special_delicacies_db` | development/alternate name |
| `special_delicacies_stage4a_test` | Stage 4A test schema |
| `special_delicacies_stage4a_acceptance_test` | Stage 4A acceptance schema |
| `special_delicacies_stage4b_test` | Stage 4B test schema |

### 3.2 Files that constitute the correction

Only these four files are required. They are the complete, minimal port:

| File | Change | Purpose |
| --- | --- | --- |
| `backend/tests/Support/TestDatabaseGuard.php` | new (96 lines) | Dependency-free guard: denylist **and** allowlist pattern, fail-closed on unresolved identity |
| `backend/tests/TestCase.php` | modified (+52 / −21) | Forces the isolated environment before boot, then asserts the guard; re-asserts in `setUpTraits()` before `RefreshDatabase`/migrations/seeds |
| `backend/tests/Unit/TestDatabaseGuardTest.php` | new (109 lines) | Pure unit regression tests (no database connection) |
| `backend/tests/Feature/Stage4bDatabaseGuardWiringTest.php` | new (67 lines) | Asserts the guard is declared on the shared base class and inherited by every Feature test |

### 3.3 Preserved properties

- **Fail-closed:** an empty or unresolved database identity throws; it is never assumed safe.
- **Disposable-database allowlist:** a MySQL schema is accepted only when it matches
  `^special_delicacies_[a-z0-9_]+_test$` **and** is not in the denylist. The prior-stage
  `*_test` schemas match the pattern, so the denylist is what stops them.
- **Every relevant setup path:** `createApplication()` runs before the framework boots and
  `setUpTraits()` runs before any destructive trait setup. Every Feature test extends
  `Tests\TestCase`, which `Stage4bDatabaseGuardWiringTest` asserts by scanning
  `tests/Feature/*Test.php`. Verified against PR #1's tree: **every** Feature test on
  `audit-deposits-credit` extends `Tests\TestCase`, so the wiring test passes there unmodified.
- **PR #1's existing implementation preserved:** the port replaces only the weak guard inside
  `backend/tests/TestCase.php`. No application code, route, controller, model, service,
  migration or frontend file changes.

### 3.4 Integration status

**Verified and ready, not yet present in PR #1's head.** See §8 for the exact obstacle and
§12 for the routes to complete it.

---

## 4. Projected PR #1 diff assessment

The projected diff was computed by applying exactly the four files above to
`origin/audit-deposits-credit` in a throw-away worktree and diffing against `origin/main`
(the worktree was removed afterwards; no branch was modified).

| | Current PR #1 | Projected PR #1 (guard ported) |
| --- | --- | --- |
| Files changed | 40 | **43** |
| Insertions | 4118 | **4421** |
| Deletions | 94 | **94** |
| New files | — | `TestDatabaseGuard.php`, `TestDatabaseGuardTest.php`, `Stage4bDatabaseGuardWiringTest.php` |
| Modified files | — | `backend/tests/TestCase.php` |

Deletions are unchanged because PR #1 had already replaced `main`'s `Tests\TestCase` wholesale;
the port only rewrites that replacement.

**No unrelated Stage 4C implementation enters PR #1.** Checked directly:

- No file in the PR #1 diff matches `refund`, `credit-note`/`creditnote`, `reversal` or
  `stage4c` **except** the pre-existing design document
  `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` (documentation, already on
  PR #1 before this pass).
- None of PR #2's Stage 4C implementation files exist on the remediation branch:
  `RefundService.php`, `Stage4cPolicy.php`, `config/stage4c.php`, `CreditNoteService.php` are
  all absent.
- The four ported files are confined to `backend/tests/`.

---

## 5. PR #2 overlap assessment

Exactly **nine** files are touched by both pull requests. Handling is documented below; no
Stage 4C code was imported into PR #1 and no PR #2 file was modified.

| # | File | PR #1 change | PR #2 change | Handling |
| --- | --- | --- | --- | --- |
| 1 | `backend/app/Models/CustomerCreditLedgerEntry.php` | `booted()` immutability guard | fillables, `reversal`/`credit_note_credit` types, `CREDIT_TYPES`/`DEBIT_TYPES`, casts, relations, **its own `booted()`** | **`booted()` collision — see 5.1** |
| 2 | `backend/app/Services/CustomerCreditService.php` | receipt-intent `operation_reference` uniqueness pre-check in `classifyReceipt` | derived-balance query subtracting reversals, `currentBalanceMinor()` | Take both. PR #2's balance query references reversal columns from its own migration, so PR #2's migration must exist before that query runs. PR #1's uniqueness pre-check is retained. |
| 3 | `backend/app/Services/GatewayPaymentService.php` | deposits: `availableGateways`, `depositGateway`, `gatewayForDeposit`, `initializeDepositCharge`, `verifyDepositCharge`, deposit branch in `consumeWebhook` | refunds: `supportsRefunds`, `refundGatewayFor`, `initiateRefund`, `findRefundByReference`, `verifyRefund` (purely additive) | Take both method blocks. No method-name collision. Confirm both use the shared `hasCredentials`/gateway helpers and that the deposit webhook branch and refund paths do not both claim the same provider event. Run both suites. |
| 4 | `backend/routes/api.php` | verification routes, both-contact gate group, `customer/deposits*`, `admin/deposits*` | `admin/financials/refunds*`, `admin/financials/credit-notes*`, `admin/financials/credit-reversals*` (purely additive) | Keep both blocks; different path prefixes but the same insertion anchor, so expect a textual conflict. Re-run `php artisan route:list --path=api` for duplicate/missing routes. |
| 5 | `backend/tests/TestCase.php` | weak guard | corrected inline guard | **Resolved on the remediation branch.** The shared `TestDatabaseGuard` class supersedes both inline versions; when PR #2 rebases, its inline guard is replaced by the shared class rather than merged, so exactly one guard remains. |
| 6 | `docs/README.md` | two Stage 4B acceptance-report entries | Stage 4C and Stage 4D entries | Keep both; the index sections are independent. |
| 7 | `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` | design document | later design revision | Resolve to the latest Stage 4C design. Documentation only. |
| 8 | `frontend/src/App.tsx` | customer deposits route | refund/credit-note routes | Additive; keep both. |
| 9 | `frontend/src/components/account/CustomerCreditPanel.tsx` | customer credit panel changes | reversal-aware panel changes | Merge carefully, then re-run `tsc` and the production build. |

### 5.1 `CustomerCreditLedgerEntry` — duplicate `booted()` hazard (documented safe resolution)

Both pull requests add a `protected static function booted(): void` to the same class:

- **PR #1:** `static::updating()` throws `DomainException('Customer credit ledger entries are immutable.')`, `static::deleting()` throws `DomainException('Customer credit ledger entries cannot be deleted.')`.
- **PR #2:** the same two events, throwing `DomainException` with the append-only wording, plus new constants, fillables, casts and relations in the same file.

A naive merge that keeps both blocks produces two `booted()` declarations in one class, which
is a **PHP fatal error** (`Cannot redeclare …::booted()`) — the class will not load and every
credit test plus any request touching the ledger fails.

**Safe resolution (required before any PR #2 integration):**

1. Keep **exactly one** `booted()` method. Take PR #2's constants, fillables, casts and
   relations, and inside the single `booted()` keep the two listeners — one for `updating`,
   one for `deleting` — throwing `DomainException`.
2. Preserve append-only semantics: `updating` and `deleting` must still throw. PR #2's
   reversal entries are **created** (inserts are never blocked by these listeners), so
   reversals remain implementable while history stays immutable.
3. Delete the other copy entirely — do not comment it out or nest it.
4. Verify with `php -l backend/app/Models/CustomerCreditLedgerEntry.php` and by running the
   credit suites (`Stage4bCreditAndSecurityTest`, `Stage4bRemediationTest`, and PR #2's
   `Stage4cCreditReversalTest`) after the merge.

This resolution was **not** applied in this pass: it belongs to the PR #2 integration, which
remains unauthorised.

---

## 6. Documentation status

### 6.1 Seven Stage 4B reports on the correct branch

All seven closure reports exist on `stage-4b-remediation` and are referenced by
`docs/README.md` under **"Stage 4B closure remediation (2026-11-27)"**:

| # | Report | Indexed |
| --- | --- | --- |
| 1 | `STAGE-4B-OUTSTANDING-SCOPE-RECONCILIATION.md` | yes |
| 2 | `STAGE-4B-INDEPENDENT-SECURITY-REVIEW.md` | yes |
| 3 | `STAGE-4B-DATABASE-GUARD-REMEDIATION.md` | yes |
| 4 | `STAGE-4B-MYSQL-8.4.11-VERIFICATION.md` | yes |
| 5 | `STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md` | yes |
| 6 | `STAGE-4B-PR-OVERLAP-AND-INTEGRATION-REPORT.md` | yes |
| 7 | `STAGE-4B-FINAL-CLOSURE-REPORT.md` | yes |

The two earlier Stage 4B acceptance reports
(`STAGE-4B-DEPOSITS-AND-CREDIT-ACCEPTANCE-REPORT.md`,
`STAGE-4B-AUTHENTICATED-BROWSER-ACCEPTANCE-REPORT.md`) are also indexed, under
"Historical phase references". The index therefore references every Stage 4B document on the
branch, and this report is added to it in the same commit.

### 6.2 Stage 4B report accidentally present in PR #2

One Stage 4B report **is** present on PR #2's branch and must be removed before PR #2 is
considered clean:

| Item | Detail |
| --- | --- |
| File | `docs/STAGE-4B-OUTSTANDING-SCOPE-RECONCILIATION.md` |
| Branch | `refunds-credit-notes` (PR #2) |
| Introduced by | commit `ceb533b` "Add Stage 4B scope reconciliation report" |
| Content | a **different, 463-line version** of the file (the Stage 4B branch holds the authoritative 109-line version) |
| Indexed in PR #2's `docs/README.md` | **No** — no `STAGE-4B` entry exists in PR #2's index |
| Functional impact | none (documentation only, unreferenced) |

Removal could not be performed from this session (§8). The exact command for the branch owner:

```
git checkout refunds-credit-notes
git rm docs/STAGE-4B-OUTSTANDING-SCOPE-RECONCILIATION.md
git commit -m "chore(docs): remove Stage 4B report from Stage 4C branch"
git push origin refunds-credit-notes
```

No other Stage 4B document is present on PR #2, and no Stage 4B code is present on PR #2.

---

## 7. Verification results

Every command below was executed in this pass. Nothing is carried over from an earlier run.

### 7.1 Backend — guard, Stage 4B, Stage 4A, RBAC, FK/migration (in-memory SQLite)

```
php vendor/bin/phpunit --no-coverage \
  tests/Unit/TestDatabaseGuardTest.php \
  tests/Feature/Stage4bDatabaseGuardWiringTest.php \
  tests/Feature/Stage4bCreditAndSecurityTest.php \
  tests/Feature/Stage4bCustomerIdentityGatingTest.php \
  tests/Feature/Stage4bCustomerOnlineDepositTest.php \
  tests/Feature/Stage4bRemediationTest.php \
  tests/Feature/Stage4bStaffRecordedDepositTest.php \
  tests/Feature/PaymentAllocationTest.php \
  tests/Feature/CommercialDocumentsTest.php \
  tests/Feature/FinancialManagementTest.php \
  tests/Feature/CustomerIdentityTest.php \
  tests/Feature/RbacTest.php \
  tests/Feature/SecurityAndApiTest.php \
  tests/Feature/ForeignKeyRestrictionTest.php \
  tests/Feature/MigrationRollbackVerificationTest.php
```

**`OK (140 tests, 824 assertions)`** — 37.8 s.

### 7.2 MySQL 8.4.11 target version

A disposable `mysql:8.4.11` container (`sd-mysql8411`) was started on the compose network with
database `special_delicacies_guard_probe_test`. `SELECT VERSION()` returned **8.4.11** (the
sandbox development service is `mysql:8.4` → 8.4.13, so a dedicated instance was required).

The same 15-file suite, run with `BASE44_MYSQL_TEST_DATABASE=special_delicacies_guard_probe_test
DB_HOST=sd-mysql8411 DB_USERNAME=root DB_PASSWORD=`:

**`OK (140 tests, 823 assertions)`** — 48.0 s.

The one-assertion difference is expected and explained: the wiring test asserts
`':memory:'` only on the SQLite branch (`if ($connection === 'sqlite')`), so the SQLite run
makes one assertion the MySQL run does not.

### 7.3 Guard refusal — fail-closed proof on 8.4.11

Each protected name was supplied as `BASE44_MYSQL_TEST_DATABASE` against the 8.4.11 instance,
where none of those schemas exist. All five were refused **before connecting or migrating**:

```
### special_delicacies
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies].
### special_delicacies_db
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_db].
### special_delicacies_stage4a_test
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_stage4a_test].
### special_delicacies_stage4a_acceptance_test
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_stage4a_acceptance_test].
### special_delicacies_stage4b_test
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_stage4b_test].
```

`SHOW DATABASES` afterwards listed only `information_schema`, `mysql`, `performance_schema`,
`sys` and `special_delicacies_guard_probe_test` — **no protected schema was created or
modified**, and the disposable instance was removed afterwards.

### 7.4 Full backend suite (in-memory SQLite)

**`Tests: 444, Assertions: 2208, Errors: 7`** — 0 failures, 0 skipped.

All seven errors are `LogicException: GD extension is not installed` in image-upload tests
(`AdminMenuItemTest` ×4, `ChatFeatureTest`, `PaymentGatewayManagementTest`,
`UserProfileTest`). This is a pre-existing limitation of the development PHP image, unrelated
to Stage 4B, and is not introduced or masked by this work.

### 7.5 Formatting, lint, type-check and build

| Check | Command | Result |
| --- | --- | --- |
| Backend formatting | `php vendor/bin/pint --test` on the 4 changed files | **PASS (4 files)** |
| Frontend lint | `npm run lint` (oxlint, 92 files) | **0 errors**, 55 pre-existing warnings |
| Frontend type-check | `npx tsc -b --force` | **exit 0** |
| Production build | `npm run build` | **success** (`✓ built in 884ms`, postbuild route fallbacks generated) |
| Frontend RBAC | `npm run test:rbac` | **7/7 pass** |
| Backend RBAC | `tests/Feature/RbacTest.php` (within §7.1/§7.2) | pass |

The 55 oxlint warnings and the repository-wide Pint findings are pre-existing style debt, out
of scope for this pass and unchanged by it.

### 7.6 Test-equivalence argument for the projected PR #1

The four ported files are byte-identical to the versions exercised above, and the projected
PR #1 tree differs from the verified tree only by the absence of eight documentation files.
Documentation cannot affect test execution, so §7.1–§7.5 are valid evidence for the projected
integration. Additionally, every Feature test on `audit-deposits-credit` was confirmed to
extend `Tests\TestCase`, which is the precondition `Stage4bDatabaseGuardWiringTest` asserts.

---

## 8. Blocked action — integration into the PR #1 source branch

The guard is verified and ready, but **the four files could not be pushed to
`audit-deposits-credit` from this session.** The exact obstacle:

1. The sandbox Git client holds **no credentials** for `origin`. A push to any branch fails
   immediately:

   ```
   $ git push --dry-run origin HEAD:refs/heads/audit-deposits-credit
   fatal: could not read Username for 'https://github.com': terminal prompts disabled
   ```

   There is no credential helper, no `GH_TOKEN`/`GITHUB_TOKEN` in the environment, and no
   `gh` CLI on the sandbox host. Read-only fetches succeed because the repository is readable
   anonymously; writes require credentials the sandbox does not have.

2. Only the platform's automatic commit-and-push of the session's **working branch**
   (`stage-4b-remediation`) reaches GitHub. The session cannot write to a different branch,
   and the GitHub tooling available here is read-only for branch refs.

This is a capability limit, not a code problem: the remediation commit `89b36fc` **is** pushed
and is exactly `audit-deposits-credit` + one additive commit.

The same limit blocks removing the stray Stage 4B document from `refunds-credit-notes` (§6.2).

No workaround was attempted, because the only alternatives would have required rewriting
history or force-pushing a branch under formal review — both excluded by the stop conditions.

---

## 9. External production dependencies

Recorded as external production dependencies, subject to owner acceptance of the deferral.
Nothing below was enabled, simulated or fabricated in this pass.

| Dependency | Status | What is complete | What remains (owner-supplied) |
| --- | --- | --- | --- |
| **SMS provider** | **Unconfigured** | Challenge lifecycle verified (hashing, expiry, attempt limits, cooldown, single use, binding, session invalidation). Delivery is bound to `UnavailableSmsOtpDelivery`, which throws `OtpDeliveryUnavailable` → HTTP `503`. | An SMS provider implementing `SmsOtpDelivery`, bound in `AppServiceProvider`, plus credentials. |
| **Email delivery transport** | **Unconfigured in this environment** | Email challenge logic verified. `deliverEmail()` **fails closed** when the mailer is `log`/`array`; `MAIL_MAILER=log` here. | A real mail transport and `MAIL_MAILER` plus credentials. |
| **Live payment gateway / merchant verification** | **Unconfigured** | Initiation validation, server-side amount/currency checks (GHS, exact minor-unit match), deterministic references, return verification, webhook signature verification (Paystack HMAC, Korapay HMAC, Hubtel status-API reconciliation), duplicate-webhook idempotency, lifecycle transitions, credit only after verified success — all exercised with `Http::fake`. Zero `payment_gateways` rows. | Real merchant credentials, webhook registration with the provider, and an end-to-end merchant-sandbox test. |

No real email, SMS or payment delivery was triggered, and none is claimed.

---

## 10. Outstanding risks

| ID | Risk | Severity | Status |
| --- | --- | --- | --- |
| R1 | **PR #1's head still carries the weak guard** until the port lands; a misconfigured MySQL run against that branch could `migrate:fresh` a protected schema. | **Merge-blocking** | Guard verified and ready on `stage-4b-remediation`; blocked on §8. |
| R2 | `CustomerCreditLedgerEntry` duplicate `booted()` would fatal on a naive PR #1 + PR #2 merge. | High (merge-time) | Documented safe resolution in §5.1; not applied (PR #2 integration unauthorised). |
| R3 | Stray Stage 4B report on PR #2 (`ceb533b`). | Low (docs only, unindexed) | Removal command in §6.2; blocked on §8. |
| R4 | `CustomerCreditService` balance query in PR #2 depends on reversal columns from PR #2's own migration — ordering hazard if PR #1's version is replaced early. | Medium (merge-time) | Documented in §5; PR #2 migration must exist before that query runs. |
| R5 | `routes/api.php` and `GatewayPaymentService` share insertion anchors; textual conflict likely at merge. | Low (mechanical) | Additive and compatible; both blocks retained; re-run route list and both suites. |
| R6 | SEC-01 … SEC-04 (four low-severity hardening items from the independent security review). | Low | Tracked for a follow-up; no Critical/High finding. |
| R7 | 7 image-upload tests error because GD is absent from the development PHP image. | Low (environment) | Pre-existing; not a Stage 4B blocker. |
| R8 | True multi-connection concurrency was not stress-tested; `lockForUpdate` paths executed under InnoDB and idempotency is covered by tests. | Low | Stated limitation, unchanged. |

---

## 11. Stop-condition compliance

| Condition | Status |
| --- | --- |
| Do not merge PR #1 | Not merged (`audit-deposits-credit` untouched at `6d29dd7`) |
| Do not merge PR #2 | Not merged (`refunds-credit-notes` untouched at `ceb533b`) |
| Do not modify `main` | `main` unchanged at `95d6517` |
| Do not modify protected databases | Not modified; the sandbox `special_delicacies` was never used by the suite (the guard refuses it, proven in §7.3) |
| Do not perform destructive Git operations | None: no reset, clean, stash, force-push, rebase or history rewrite. The only Git objects created were in a throw-away worktree, since removed |
| Do not introduce Stage 4C implementation into PR #1 | No Stage 4C implementation exists on this branch or in the projected PR #1 diff (§4) |
| Do not begin Stage 4C acceptance | Not started |
| Do not begin Stage 4D | Not started |
| Stop and report the exact obstacle if integration cannot be performed safely | Reported in §8 |

---

## 12. Recommended next action

The remaining work is one branch write plus one documentation deletion. Three safe routes, in
recommended order:

**Route A — fast-forward PR #1 to the verified commit (simplest, additive, no history rewrite).**

```
git push origin stage-4b-remediation:audit-deposits-credit
```

This moves `audit-deposits-credit` from `6d29dd7` to `89b36fc`. It is a pure fast-forward: no
merge, no history rewrite, no change to `main`, and PR #1 stays open. It brings the four guard
files **and** the eight Stage 4B documents into PR #1, which also satisfies §6.1 in the place
the Stage 4B PR lives. If it is unwanted, reverse it with a normal revert commit
(`git revert 89b36fc`) rather than a force-push — no history is rewritten either way.

**Route B — strict minimal port (guard and tests only, no documentation).**

On a checkout of `audit-deposits-credit`, take only the four files from `89b36fc`, commit and
push. This keeps PR #1's diff at 43 files / +4421 / −94 with no documentation change, matching
the narrowest reading of "port only the necessary guard correction and its regression tests".

**Route C — authorise a session branch switch.**

If the owner prefers that this session performs the port itself, authorising a move of the
working branch to `audit-deposits-credit` would let the platform push to it; the four files
would be applied and the suites re-run there before reporting back. This is the only route in
which the session itself completes the write.

Whichever route is chosen, the stray document on PR #2 (§6.2) should be removed in the same
review cycle, and §5.1 must be applied before any PR #1 + PR #2 integration.

---

## 13. Readiness classification

**CONDITIONALLY READY — the guard is verified but not yet in the PR #1 head.**

The database guard correction is complete, minimal, fail-closed, allowlisted, wired into every
destructive setup path, and proven by 140 passing tests on both in-memory SQLite and MySQL
8.4.11, including live refusal of all five protected databases. Backend formatting, frontend
lint, type-check, production build and RBAC checks all pass.

Formal acceptance should not be granted until:

1. the four guard files are present in `audit-deposits-credit` (Route A, B or C);
2. the owner formally accepts the SMS, email-transport and live-gateway deferrals (§9);
3. the stray Stage 4B report is removed from PR #2 (§6.2);
4. the `booted()` resolution (§5.1) is applied at PR #2 integration time.

PR #1 and PR #2 remain unmerged; Stage 4C acceptance and Stage 4D have not begun.

---

**Prepared for owner review and approval.**
