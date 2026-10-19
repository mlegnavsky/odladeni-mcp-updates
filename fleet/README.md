# Odladění fleet — own plugin management

## Deployed server components (2026-10-19)
- MCP index.php SHA-256: bbf20e45e00c5a52235d79d3453194d96df96c2bdf5e8962084e031b15d26e50. Immutable source commit 0525ee70946f466a53ffc02d811f0f214201f0f3, mcp-management-20261019.txt.
- OAuth-protected fleet_plugin_manage and fleet_upgrade_bridge tools, plus existing read-only fleet diagnostics.
- Console route /console/?action=plugin_manager&site=URL, linked from the site's Pluginy tab. Existing Google admin/superadmin session and CSRF protection remain required.
- Shared authenticated transport installed at assets/fleet-management.php. Exact paired registry targets, HTTPS/443, public DNS validation, cURL destination pinning, TLS verification, no proxy/redirects, bounded response and timeout.

## WordPress package
Hlavní instalace 2.4.54, immutable ZIP:
https://plaginy.odladeni.cz/releases/prvotni-instalace-v2.4.54-2666ffa1283f.zip

SHA-256: 2666ffa1283f67cef05611fdd2f93af64975b7de75914451bd4a9380e09f9edd

Public manifest: https://plaginy.odladeni.cz/api/manifest.php?plugin=prvotni-instalace
The manifest now includes the actual package SHA-256, correct bridge name and PHP 8.0 minimum. The reference ZIP includes README.md, ODLADENI.md, CHANGELOG.md and consistent manifest.json / odladeni.json / header / internal version.

## Management API
fleet_plugin_manage accepts site_url, action, exact plugin basename and stable request_id. Actions: activate, deactivate, auto_update, install, update, delete. auto_update requires boolean enabled; delete requires explicit confirm_delete. Install source: wordpress, odladeni or zip; source zip requires HTTPS URL and SHA-256. Regular updates use the installed WordPress updater, including premium updater packages. New installs stay inactive. Upgrade preserves active state.

POST /wp-json/odladeni-bridge/v2/plugin-manage uses the existing paired key with HMAC over POST, route, timestamp, nonce and SHA-256 of the exact JSON body. Atomic replay protection, persistent request receipts, request-id collision rejection and an exclusive operation lock are implemented in odladeni-plugin-manager.php. Keys never leave the services.

The bridge cannot deactivate/delete itself. Remote mutations reject multisite. WordPress file-modification restrictions are respected. Delete removes inactive plugin files after an outside-web-root ZIP backup; it deliberately retains database settings and does not run uninstall hooks. File backups do not cover database migrations. Automatic restoration is not implemented; the backup ID enables administrator recovery. Updates/deletes refuse to proceed without a private backup directory and ZIP support.

ZIP checks cover SHA-256 for own/custom packages, basename/root identity, version where supplied, entry traversal, symlinks, 25 MiB download, 128 MiB expanded size and 10,000-entry limits. Native/cron Odladění package downloads use the same integrity checks. Other registered WordPress updater sources retain native HTTPS/WordPress trust.

## Verification and remaining deployment step
- All PHP files in the final plugin package passed local PHP 8.3 syntax checks; server validator accepted release 2.4.54 including PHP lint and integrity.
- Regression checks passed for HMAC/body tampering, replay, activation/deactivation, idempotency/conflicts, invalid paths, bridge protection, active deletion rejection and native auto-update flags.
- Package tests passed for valid ZIP, traversal, wrong root/main/header/version, SHA mismatch, native repository integrity hook, exact backup contents and symlink rejection. The test's scratch-directory reuse issue was fixed by using random per-run directories; no product change was required.
- MCP stage/apply and postdeploy authenticated health checks passed; previous MCP SHA 3ea511433e80b86f348af799ef3c9074935d2b984171aa90f554747d3cfe8222 remains the backup.
- Existing live signed diagnostics still succeeded on all four paired sites after deployment. WordPress detected the bridge update.
- Package 2.4.54 was published, but the first WordPress bridge upgrade and live mutation checks had NOT been performed at this checkpoint. No site plugin was changed or deleted. The browser requires a Google administrator login and the current chat tool catalog has not refreshed the new management tools.
- End-to-end install/update/delete, cron execution, rollback, native plugin-details dialog and hostile-network fixtures remain unverified. Local tests use WordPress API stubs and real ZIP/filesystem fixtures, not a full WordPress installation.

## Continue
Refresh the connected MCP tool catalog, then fleet_upgrade_bridge on the explicitly selected pilot site (hyperkostka.cz) with confirm=true. Read fresh diagnostics and confirm 2.4.54 active. Use fleet_plugin_manage to perform the already requested Angie deactivation with a new stable request ID, then verify its inactive state. Alternatively use the own console manager after the administrator signs in. Do not route through WPVibe or run mutations through read-only tools.

## Tests
php tests/plugin-manager-test.php "$PWD/fleet/odladeni-plugin-manager.php"
php tests/package-test.php "$PWD/fleet/odladeni-plugin-manager.php"

PHP ctype and zip extensions are needed by the respective tests. OdladeniFleetReadOnly.php is the original design reference; production runtime is embedded atomically in MCP index.php. No credentials or site diagnostic dumps belong in this repository.
