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
- [Stage 4C Implementation and Acceptance Report](STAGE-4C-IMPLEMENTATION-AND-ACCEPTANCE-REPORT.md) — Refunds, credit notes and customer credit reversals: independent verification, database-safety audit, D1–D13 matrix, defects and remediation, SQLite/MySQL 8.4.13 evidence, responsive browser acceptance and provider capability status.
- [Stage 4C Owner Decision Register](STAGE-4C-OWNER-DECISION-REGISTER.md) — D1–D13: original decision, recommended default, implemented behaviour, configuration key, consequences, approval status and current restriction; includes the D10 credit-note linkage options.
- [Stage 4C Design](STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md) — Historical design record (D1–D13, workflows and risks) preserved from discovery.
- [Stage 4C Initial Report](STAGE-4C-INITIAL-REPORT.md) — Historical discovery report preserved from discovery.

## Stage 4D — Financial reporting and reconciliation (design)

- [Stage 4D Initial Discovery Report](STAGE-4D-INITIAL-DISCOVERY-REPORT.md) — Branch and PR state, Stage 4A/4B/4C presence, authoritative financial sources, existing reports, findings G1–G13, provider settlement capability, classification.
- [Stage 4D Design](STAGE-4D-FINANCIAL-REPORTING-AND-RECONCILIATION-DESIGN.md) — Modules A–G, financial position identities, API surface, additive schema, aggregation rules, performance plan, risks.
- [Stage 4D Financial Metrics Register](STAGE-4D-FINANCIAL-METRICS-REGISTER.md) — Every metric with formula, source, inclusion/exclusion, currency treatment and date basis.
- [Stage 4D Permissions Matrix](STAGE-4D-PERMISSIONS-MATRIX.md) — Existing financial permissions, proposed reporting permissions, role matrix, route enforcement.
- [Stage 4D Stage 4C Dependency Matrix](STAGE-4D-STAGE-4C-DEPENDENCY-MATRIX.md) — Which reports depend on which Stage 4C artifacts, integration contracts, missing-dependency behaviour.
- [Stage 4D Implementation Breakdown](STAGE-4D-IMPLEMENTATION-BREAKDOWN.md) — Phases 4D-0…4D-8 with deliverables, exit criteria and dependency graph.
- [Stage 4D Testing and Acceptance Strategy](STAGE-4D-TESTING-AND-ACCEPTANCE-STRATEGY.md) — Invariants, per-module acceptance, permissions, exports, performance, responsive acceptance.
- [Stage 4D Owner Decision Register](STAGE-4D-OWNER-DECISION-REGISTER.md) — D1–D14 with options, recommendations, blocking status and approval record.

## Historical phase references

Files named `PHASE-*.md` preserve decisions and implementation details from their original development phase. Some phase statements predate later updates. Use the current operating documents and `php artisan route:list --path=api` as the source of truth for the present system behavior.
