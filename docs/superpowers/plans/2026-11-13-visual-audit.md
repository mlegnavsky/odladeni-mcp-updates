# Visual audit implementation plan
Goal: implement the approved public overview and protected internal report.
Architecture: server-owned presentation whitelist and assessment, consumed by responsive UI and compact PDF; raw internal evidence only via administrator endpoint.
Spec: docs/superpowers/specs/2026-11-13-visual-audit.md
- [x] Add assessment/privacy tests, confirm failure, implement presentation.php and whitelist integration. Tests: incomplete score null, classification mismatch, unknown purpose, no nested raw evidence leaked, admin retains evidence.
- [x] Replace public results with four scorecards, category bars, consent phases, thumbnails and same-host saved comparison. Keep detailed admin panel and internal PDF.
- [x] Package, run all PHP/Node checks, inspect rendered PDF/desktop/mobile, deploy to selected site, verify API redaction and live scan/reopen. Commit source and verification.
Ruling: equal-weight area means instead of weighting the score by cookie count; missing or unverified checks score no points, incomplete areas block overall percent. Critical errors have independent red warning.

Review: four important issues reproduced and fixed; all regression checks green. Native mobile viewport visual check unavailable; desktop UI, actual export, same-domain comparison and live scan/reopen verified.
