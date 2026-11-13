# Cookie progress and EU assessment Implementation Plan

> Execute inline, task-by-task with tests and one final review.

**Goal:** Live measuring screen and evidence-based cookie EU conditions.
**Architecture:** PHP safe progress and compliance projection; worker activity and initial-choice evidence; pure JS scan renderer integrated with owner/shared polling; dashboard/PDF summary.
**Tech Stack:** existing WordPress PHP8, JS, isolated Firefox worker, jsPDF.
**Spec:** docs/superpowers/specs/2026-11-13-cookie-progress-eu-design.md

## Constraints
- No timer-derived measurement percent, unknown never pass, no technical evidence public.
- Preserve existing method2.0 scores, archive/hash links and browser isolation.
- Hide results while running; failure/partial state remains explicit.

## Review Focus
- All-green average with known consent violation must still fail EU conditions.
- Shared running URL refreshes read-only and never starts/resumes work.
- Worker queue and retries never invent progress or show final result early.
- Legacy reports lack new control observations: unavailable, never fabricated success.
- Narrow phone/reduced motion and long PDF condition labels remain usable.

- [x] Write failing tests for PHP progress/EU conclusions, JS scan stages, new initial-choice evidence and dashboard verdict.
- [x] Implement safe PHP progress/compliance and worker evidence/activity. Test against missing, failed, warning, malformed/stale data and legacy reports.
- [x] Implement measuring UI and owner/shared polling with final-only results. Add EU summary to dashboard and PDF.
- [x] Run complete PHP/Node suites, package lint, review, deploy only gdpr-cookies.cz; browser verify a new scan, stored reports and PDF; persist Git.
