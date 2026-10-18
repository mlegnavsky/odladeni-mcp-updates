# Central fleet management — integration plan

Branch: `feature/central-fleet-management`

## Existing facts
- The Odladění MCP exposes `list_connected_sites`, but that tool is registry-only.
- The first-party plugin release `prvotni-instalace` 2.4.51 includes `odladeni-remote-diagnostics.php`.
- Its `GET /wp-json/odladeni-bridge/v1/diagnostics` endpoint reports WordPress/PHP versions, installed plugins, active state, update availability, and WordPress auto-update flag.
- The endpoint uses per-site shared-key HMAC with timestamp and nonce replay protection.
- The web console already displays a Hyperkostka overview, but that does not establish that the MCP runtime can issue authenticated site calls.

## Required server-side wiring (not implemented yet)
1. Identify and reuse the console's existing authenticated per-site request function. Do not create new shared secrets or expose them to ChatGPT.
2. Implement registry callback by reading the existing paired-site SQLite database with the current schema. Never accept arbitrary URLs as request targets.
3. Implement authenticated transport callback using the existing site's HMAC signing and nonce logic. Reject redirects, non-HTTPS destinations, private IP targets, and origin mismatches. Timeouts and bounded responses required.
4. Instantiate `OdladeniFleetReadOnly` only *after* MCP OAuth authorization.
5. Register MCP tools `fleet_list_sites`, `fleet_site_diagnostics`, `fleet_diagnostics` and expose their JSON schemas in `tools/list`. Route calls in `tools/call`.
6. Test first with Hyperkostka, then all four currently paired sites; verify plugin 2.4.51 diagnostic response and auto-update flags.
7. Add mutation tools in a separate reviewed change: plan/dry-run, per-site permissions, explicit user confirmation, per-site backup/rollback, idempotency keys, audit log, and bounded concurrency for up to 70 sites.

## Security
Never commit API credentials, HMAC secrets, OAuth tokens, site database, or diagnostic responses containing sensitive data to this public repository. No write operations are present in phase 1.

## Acceptance checks
- Unknown/unpaired URL rejected.
- No authorization -> no fleet tools.
- All registry sites included without manual ChatGPT reconnection.
- One unreachable site does not prevent other diagnostics.
- Auto-update flag read from WordPress per plugin, not inferred from whether the plugin is active.
- Zero WordPress writes during phase 1.
