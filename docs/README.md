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
- [Stage 4C Implementation and Acceptance Report](STAGE-4C-IMPLEMENTATION-AND-ACCEPTANCE-REPORT.md) — Refunds, credit notes and customer credit reversals: schema, services, APIs, RBAC, tests, MySQL 8.4.13 results, browser acceptance and the D1–D13 approval register.
- [Stage 4C Design](STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md) — Historical design record (D1–D13, workflows and risks) preserved from discovery.
- [Stage 4C Initial Report](STAGE-4C-INITIAL-REPORT.md) — Historical discovery report preserved from discovery.

## Historical phase references

Files named `PHASE-*.md` preserve decisions and implementation details from their original development phase. Some phase statements predate later updates. Use the current operating documents and `php artisan route:list --path=api` as the source of truth for the present system behavior.
