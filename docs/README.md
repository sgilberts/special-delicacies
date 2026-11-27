# Documentation Index

## Current operating documentation

- [Features and Operations](FEATURES-AND-OPERATIONS.md) — Current public ordering, QR, delivery, payment, branding, admin, theme, and release checks.
- [Paystack Gateway Setup](PAYMENT-GATEWAY-SETUP.md) — Merchant key configuration, checkout flow, webhook, deployment requirements, and troubleshooting.
- [Installation Guide](INSTALLATION-GUIDE.md) — Local setup, database migration, frontend build, and deployment pointers.
- [Broadcasting Deployment](broadcasting-deployment.md) — Local Reverb, WebSocket/TLS proxy, hosted broadcaster fallback, and monitor troubleshooting.
- [API Documentation](API-DOCUMENTATION.md) — Current public and authenticated API routes.
- [Database Schema](DATABASE-SCHEMA.md) — Current business tables, including payment gateway/order fields.
- [Architecture](ARCHITECTURE.md) — Application structure, data flow, and security boundaries.
- [Project Overview](PROJECT-OVERVIEW.md) — Current product scope and stack.
- [Implementation Log](IMPLEMENTATION-LOG.md) — Implementation history and verification notes.
- [Payment Result and Order Tracking](PAYMENT-RESULT-AND-ORDER-TRACKING.md) — Verified payment return, customer tracking, and public read caching.
- [Phase 2 Caching, Tracking and Chat](PHASE-2-CACHING-TRACKING-CHAT.md) — Authenticated cache rules, Reverb tracking, chat permissions, APIs, attachments, and verification.

## Historical phase references

Files named `PHASE-*.md` preserve decisions and implementation details from their original development phase. Some phase statements predate later updates. Use the current operating documents and `php artisan route:list --path=api` as the source of truth for the present system behavior.

- [Stage 4B Deposits and Customer Credit Acceptance Report](STAGE-4B-DEPOSITS-AND-CREDIT-ACCEPTANCE-REPORT.md) — Delivered deposit workflows, credit ledger invariants, verification results, and deferred items.
- [Stage 4B Authenticated Browser Acceptance Report](STAGE-4B-AUTHENTICATED-BROWSER-ACCEPTANCE-REPORT.md) — Email-or-phone customer login, both-contact verification gate, authenticated browser acceptance, PR #1 diff review, and outstanding dependencies.

## Stage 4B closure remediation (2026-11-27)

- [Stage 4B Outstanding Scope Reconciliation](STAGE-4B-OUTSTANDING-SCOPE-RECONCILIATION.md) — Two-tranche status, outstanding-item closure, and external production dependencies.
- [Stage 4B Independent Security Review](STAGE-4B-INDEPENDENT-SECURITY-REVIEW.md) — Identity, account gating, authorization boundaries, and financial findings (no Critical/High).
- [Stage 4B Database Guard Remediation](STAGE-4B-DATABASE-GUARD-REMEDIATION.md) — Merge-blocking protected-database guard fix, regression tests, and live refusal evidence.
- [Stage 4B MySQL 8.4.11 Verification](STAGE-4B-MYSQL-8.4.11-VERIFICATION.md) — Isolated MySQL 8.4.11 run: migrations, deposits, credit, identity, guard, and FK results.
- [Stage 4B Browser Acceptance Report](STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md) — Super-admin, independent verifier, invoice allocation, denial, and HMR evidence.
- [Stage 4B PR Overlap and Integration Report](STAGE-4B-PR-OVERLAP-AND-INTEGRATION-REPORT.md) — PR #1 / PR #2 overlap analysis and safe integration strategy.
- [Stage 4B Final Closure Report](STAGE-4B-FINAL-CLOSURE-REPORT.md) — Final classification, conditions, test totals, and recommended next action.
