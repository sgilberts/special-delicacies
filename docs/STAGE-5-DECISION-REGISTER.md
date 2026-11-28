# Stage 5 — Decision Register

**Project:** Special Delicacies
**Scope:** Phase 11B.2 Stage 5 (Bookings, Reservations and Future Module Integration)
**Opened:** 28 November 2026
**Owner:** Project owner (final authority on business policy and stage acceptance)

Each entry records a decision that Stage 5 cannot make on its own. Nothing below is treated as approved. Where a decision blocks work, the affected stage stops rather than inventing a policy.

**Status values:** `OPEN` (blocking), `OPEN (non-blocking)`, `DECIDED` (recorded with date and owner answer).

---

## D-1 — Refund policy and refund workflow

**Status:** OPEN — blocks Stage 5.6 and the refund portions of 5.8/5.9.

**What was verified:** No refund table, model, service, endpoint, permission, or UI exists. `refunded` is only a `payment_transactions.status` value counted by `FinancialController` reporting. No credit-note functionality exists. Cancellation policy (windows, forfeiture, partial refunds) is not defined anywhere in the repository.

**Why it blocks:** Stage 5.6 requires cancellation refunds to "use the existing refund workflow and permissions", and Stage 5.9 requires audit of "refund initiation and outcome". There is nothing to integrate with, and the mandate forbids inventing a financial policy.

**Options**
- **A (recommended):** Stage 5 delivers cancellation that preserves records and *requests* a refund, with no money movement. A refund record, permission and workflow are authorised as a separate additive piece of work (Stage 5.6b or Stage 6), after the owner defines the cancellation/refund policy.
- **B:** Stage 5 includes a minimal additive refund record (status, amount, actor, reason, provider reference) with `financials.reservation_refunds.initiate`, still requiring manual execution in the gateway dashboard — no automatic refunds.
- **C:** No refund handling in Stage 5; staff resolve cancellations outside the system and the reservation only records the cancellation.

**Decision needed:** which option, plus the cancellation/refund policy (deposit refundable until when, partial retention, approval authority).

---

## D-2 — Reservation deposit percentages

**Status:** OPEN — blocks Stage 5.5 configuration and any reservation that requires a deposit.

**What was verified:** No deposit percentage, amount, or policy is stored or configured anywhere in the application.

**Decision needed:** the three default percentages required by Stage 5.5A:
1. Single-table reservations — ____ %
2. Multiple-table reservations — ____ %
3. Exclusive venue reservations — ____ %

Also: whether any category is exempt from a deposit (0 %), and whether a minimum/maximum absolute deposit amount applies.

**Behaviour until decided:** the deposit-settings table ships empty, and confirmation of a deposit-bearing reservation is refused with an explicit "deposit configuration missing" error, as the mandate requires. No placeholder percentage will be seeded.

---

## D-3 — Quotation-derived reservations

**Status:** OPEN (non-blocking for 5.1–5.4).

**What was verified:** `commercial_documents` has no deposit field, deposit percentage, or deposit schedule. `terms` and `notes` are free text.

**Decision needed:**
1. Confirm that a reservation created from an approved quotation uses `commercial_documents.total` as the booking value. (Recommended: yes.)
2. Confirm that the deposit percentage comes from the category default / staff override, and that no deposit term is parsed out of free-text `terms`. (Recommended: yes — parsing prose would create an unauditable financial term.)
3. Confirm that no second financial obligation is created when a quotation already exists (the reservation references the document; it does not duplicate it).

---

## D-4 — Temporary holds

**Status:** OPEN (non-blocking for 5.1–5.3).

**What was verified:** nothing in the application models temporary capacity holds.

**Decision needed:** whether an `awaiting_deposit` reservation should hold its tables/venue for a limited period.
- **A (recommended for v1):** no holds. Capacity is consumed only by persisted allocations created at approval; an unpaid request never permanently blocks a resource, and staff can release allocations manually.
- **B:** holds with an explicit expiry (single nullable `hold_expires_at` on the reservation plus a scheduled expiry command), after which allocations are released and the reservation moves to `expired`.

If B: the hold duration per category, and whether staff may extend it.

---

## D-5 — Table registry and dine-in interaction

**Status:** OPEN — blocks Stage 5.2.

**What was verified:** `restaurant_tables` (`table_number` globally unique, `capacity`, `is_active`) exists for dine-in QR ordering and has no venue, type, availability window, or reservation flag. `orders.restaurant_table_id` records dine-in occupancy but there is no occupancy window, so dine-in orders do not make a table unavailable for a future time.

**Decision needed:**
1. **Table registry:** extend `restaurant_tables` with nullable reservation columns (recommended — one physical table stays one record, avoiding two conflicting identities), or create a separate `reservation_tables` registry.
2. **Dine-in interaction (D-5b):** should availability treat a live dine-in order on a table as blocking a reservation for the same window? Recommended: **no** in Stage 5 (reservations consider reservation allocations, closures and service periods only), with the extension point documented. Including live orders changes availability results and would need the owner's confirmation of the operational rule.
3. Whether table `table_number` uniqueness should become per-venue if venues are introduced (recommended: keep global uniqueness in Stage 5 to avoid changing existing QR behaviour).

---

## D-6 — Creating invoice-purpose receipts

**Status:** OPEN (non-blocking for 5.1–5.4; relevant to 5.6).

**What was verified:** `invoice`-purpose payment transactions are read by `PaymentAllocationService` and `CustomerCreditService` but are created by no code path; every `PaymentTransaction::create()` is order-bound.

**Decision needed:** whether Stage 5 should close this pre-existing gap, and how.
- **A (recommended):** Stage 5 does **not** touch it. Reservation deposits get their own `payment_purpose = 'reservation_deposit'` and a `reservation_id` link, so they are financially visible without depending on a creation path that does not exist.
- **B:** Authorise a small additive staff "record a received invoice payment" path (cash/bank) as separate work, since it also unblocks the Stage 4B/4C screens.

---

## D-7 — Stage 4D scope confirmation

**Status:** OPEN — blocks the "integrate with Stage 4D" acceptance criteria of 5.6/5.10.

**What was verified:** reporting and reconciliation exist in substance (`FinancialController` snapshot/reports/transactions/export, permissions `financials.reports.view`, `financials.reconciliation.view`, `financials.reports.export`, `financials.payments.confirm_cash`), but nothing in the repository names Stage 4D, and `/admin/financials/reconciliation` reuses the transactions handler rather than a distinct reconciliation workflow.

**Decision needed:** confirm that the accepted Stage 4D scope is exactly the existing financial reporting/reconciliation foundation in `FinancialController` plus the financial permissions, and that Stage 5 satisfies "integrate with Stage 4D" by making reservation deposits visible in those existing reports (via payment purpose and reservation link) **without** changing 4D behaviour.

---

## D-8 — Payable extension for reservation deposits

**Status:** OPEN — blocks Stage 5.6 implementation.

**What was verified:** `GatewayPaymentService` is hard-bound to `Order` (`initialize(Order)`, `verify()` resolves by `orders.payment_reference`, all webhook branches resolve an `Order`, amount checks compare to `orders.total`). There is no payable abstraction, and a second gateway engine would violate the one-financial-engine rule.

**Options**
- **A (recommended):** additive payable extension — nullable `reservation_id` on `payment_transactions`, a `reservation_deposit` purpose, a `ReservationPaymentService` that reuses the existing `PaymentGateway` records and `PaymentTransaction` table, and extraction of the provider signature verification into a shared verifier used by both order and reservation paths. Order behaviour is unchanged and covered by existing tests.
- **B:** generalise the existing service around a `Payable` interface implemented by `Order` and `Reservation`. Cleaner long-term, but it refactors accepted order-payment code.
- **C:** reservations only accept staff-recorded deposits (no online deposit). Simplest, but does not meet the online-deposit requirement of 5.6.

**Decision needed:** A, B, or C. If A or B, confirm that extracting the signature verifier is acceptable as a no-behaviour-change refactor with regression tests.

---

## D-9 — Organisation reservation permissions

**Status:** OPEN (non-blocking for 5.1–5.6).

**What was verified:** `OrganisationAuthorization` grants a fixed permission set per role (`owner`, `administrator`, member). Organisation members can act only within those grants.

**Decision needed:** which organisation roles may (a) view organisation reservations, (b) create/cancel organisation reservations. Recommended: `owner` and `administrator` get `reservations.view` and `reservations.manage`; plain members get neither until the owner says otherwise.

---

## D-10 — Notification delivery

**Status:** OPEN (non-blocking; affects 5.9 acceptance).

**What was verified:** the application has transactional mail (`AccountOtpMail`, `PasswordRecoveryMail`), Reverb broadcast events, and chat notifications. There is no `Notification` class, no `notifications` table, and no queued job class; `.env.example` sets `MAIL_MAILER=log`.

**Decision needed:** confirm that reservation notifications extend the existing mail + broadcast infrastructure (recommended), and state which events are email versus in-app only. Real customer email requires a configured mail transport, which is outside Stage 5.

---

## Summary

| ID | Topic | Blocking | Affected stages |
|---|---|---|---|
| D-1 | Refund policy and workflow | Yes | 5.6, 5.8, 5.9 |
| D-2 | Deposit percentages per category | Yes | 5.5, 5.6 |
| D-3 | Quotation-derived reservations | No | 5.5 |
| D-4 | Temporary holds | No | 5.3, 5.4 |
| D-5 | Table registry and dine-in interaction | Yes (5.2) | 5.2, 5.3 |
| D-6 | Invoice-purpose receipt creation | No | 5.6 |
| D-7 | Stage 4D scope confirmation | Yes (5.6) | 5.6, 5.10 |
| D-8 | Payable extension approach | Yes | 5.6 |
| D-9 | Organisation reservation permissions | No | 5.7 |
| D-10 | Notification delivery | No | 5.9 |

**Recommended sequence:** answer D-5 to unblock 5.1/5.2, then D-2/D-3/D-4 before 5.5, and D-1/D-7/D-8 before 5.6. Stages 5.1–5.4 can begin on approval of this report and D-5 alone.
