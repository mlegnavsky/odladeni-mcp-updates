# Poslanecka-snemovna.cz: direct design deployment through Odladění

## Authenticated route (no browser login)

1. Match the exact HTTPS site URL through `fleet_list_sites`.
2. `file_list({file:"templates"})` exposes `deployment_file:"templates/_deploy.json"`.
3. Read `templates/_deploy.json`; retain its current SHA-256 and the documented command protocol.
4. Read the chosen design's current revision. Run `scripts/check_odladeni_native_design.py` against the exact payload first.
5. Execute `file_write` on `templates/_deploy.json` with its current SHA-256 and base64 JSON:
   `{"action":"start","design_id":"<read exact ID>","design_revision":<read revision>,"site_url":"<exact registry URL>","request_id":"<stable operation ID>"}`.
6. Keep the same request ID. Follow `step` with the exact `job_id` and `revision` returned by the preceding result. Continue until `phase:done`.
7. If an outcome is unknown, read `status` with the exact job ID. Never start a second deployment to resolve uncertainty.
8. The importer must retain its verified private backup and compare-and-swap checks. `restore` is a separate explicitly authorized action.
9. Inspect WordPress documents and the actual public site after import. Record desktop/tablet/mobile, navigation, original logo, login gating and protected analytics. An importer receipt does not prove visual parity.

## Diagnosed 2026-11-08

The MCP connection is authenticated and writable. Browser login and a separate website Git repository are not prerequisites. Earlier attempts incorrectly treated this as an access problem.

The actual 1.7 package fails the native import gate: header/footer are only in a child theme; the payload uses external CSS and HTML layout widgets. Review evidence is also incomplete. The importer explicitly rejects nonempty `styles`, HTML/shortcode widgets without a verified adapter and theme-only page settings. Do not mark these checks passed or weaken the gate to force an import.

Convert the approved appearance to native Elementor documents, a shared native header/footer, registered controls, a global Kit and real WP menus. Keep the existing login page, legal documents, users, election databases and analytical plugins. Never deploy the demo cookie-consent dialog or replace existing legal text with draft template text.

## Permanent prevention

Run the static preflight before presenting a reusable template as installable. Keep design approval, import verification and live deployment as separate recorded states. Never assert successful deployment without inspecting the site itself.

Tests: `python3 -m unittest discover -s scripts/tests -p 'test_native_design_preflight.py'` with `PYTHONPATH=scripts`.
