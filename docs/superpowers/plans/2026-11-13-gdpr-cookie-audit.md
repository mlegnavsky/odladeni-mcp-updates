# GDPR Cookie Audit Implementation Plan

Goal: deploy an honest, useful visual cookie and policy auditor with PDF on the existing site.
Spec: projects/gdpr-cookie-audit/DESIGN.md
Stack: PHP WordPress, vanilla JS/CSS, bundled jsPDF and Czech font, optional browser worker.

## Tasks
- [x] 1. Tests first: URL validation, relative links, policy extraction, cookie parsing, source
  integrations and unverified dynamic checks. Implement bounded DNS-pinned fetching and analysis.
- [x] 2. Add scan-session REST endpoints: token-bound start/step, per-IP/domain limits, one-hour
  expiry, no private URLs, no arbitrary remote content in HTML. Probe actual hosting capabilities.
- [x] 3. Build mobile dashboard, real counts and SVG charts, expandable evidence and policy table;
  show browser scenarios as unverified unless executed; bundle PDF export with Czech characters.
- [ ] 4. Test against fixtures and live websites; inspect desktop/mobile and exported PDF.
  Independent review before deployment. Install plugin and connect existing buttons using revision
  checks; verify end to end. Commit source and receipts on feature branch.

Review focus: redirects to private hosts, long/unreachable pages, external policy hosts, source
detection mistaken for browser execution, PDF escaping/Czech text, partial scan marked successful.
