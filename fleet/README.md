# Central fleet diagnostics — deployed 2026-10-19

Production: https://plaginy.odladeni.cz/mcp/

The runtime implementation is embedded in index.php to allow atomic single-file deployment and rollback. OdladeniFleetReadOnly.php remains the original design reference and is not loaded by production.

## Implemented
- OAuth-protected fleet_list_sites, fleet_site_diagnostics (site_url) and fleet_diagnostics tools.
- Existing list_connected_sites accepts include_diagnostics=true for the same live fleet report, preserving its registry-only default.
- Existing SQLite paired-site registry and per-site HMAC timestamp/nonce protocol; no new credentials.
- Strict registered URL selection, HTTPS/443 only, public DNS validation and cURL destination pinning, no proxy or redirects, TLS verification, 8-second per-site timeout and 2 MiB response limit.
- Independent failures, 48-second fleet budget before starting another site (DNS resolution itself is subject to system resolver timing).
- Only GET diagnostics; no WordPress mutations. Only selected scalar plugin metadata is returned.

## Verified deployment
- Source baseline SHA-256: 56e6679542051f7ec54e9b003444ed5f9c82fa2b3d1dd10ef35dd8dc1128f770
- Deployed SHA-256: 3ea511433e80b86f348af799ef3c9074935d2b984171aa90f554747d3cfe8222
- Immutable source commit: 5091fdbc4268eb439c7548a01eec4a401710d5a3, file mcp-fleet-20261019.txt
- Server PHP 8.4.26 TOKEN_PARSE check passed; this is syntax validation, not a test suite.
- deploy_apply confirmed exact new SHA and saved previous version as backup. Trace c0685c732132e91e.
- Live include_diagnostics test before deployment returned registry only; after deployment returned four successful signed diagnostic responses, including plugin versions, active state, update availability and auto_update flags.
- connection_diagnostics confirmed ready, authenticated, expected deployed SHA and backup SHA. Trace 1e44898ee02d9064.
- No plugin packages were published or installed, and no WordPress settings were changed.

## Limits
Standalone new tool discovery in a refreshed client, unauthorized requests, hostile DNS/redirect/oversize fixtures, partial-failure fixtures and rollback were not exercised in this deployment. No local PHP executable or automated PHP test suite was available. Server version remains 2.6.1; identify this deployment by SHA-256.

## Deploy / recovery
Use verified SHA-256 stage -> syntax check -> compare-and-swap apply -> live diagnostics. Use deploy_rollback for recovery and verify server status and SHA afterward. Never replace current production with an older reference source.

Write operations and fleet plugin upgrades are outside phase 1.
