# Odladeni console – plugin table upgrade

Branch: feature/console-plugin-table

## Goal
- Exactly one compact row per plugin with a subtle separator.
- Active state: green check icon; inactive: neutral minus.
- Details: icon button expands purpose, features and latest changes on demand.
- Available update: compact action button.
- Automatic updates: per-plugin toggle, synchronized with WordPress `auto_update_plugins`.
- On successful POST to WordPress, reread diagnostics and display the confirmed state.
- On failure, show an error; never falsely switch the displayed state.
- No change to plugin activation when toggling auto-updates.

## API
WordPress bridge `POST /wp-json/odladeni-bridge/v1/auto-update`:
`{"plugin":"slug/main.php","enabled":true}`.
Authenticate using the existing HMAC pairing secret, timestamp and nonce. CSRF and administrator role checks are mandatory on the console. Require HTTPS and verify TLS.

Diagnostics `GET /wp-json/odladeni-bridge/v1/diagnostics` should return `auto_update`, `description` and optional `changelog` for each plugin.

## Release process
1. Read the deployed `console/index.php` with verified SHA256.
2. Create a server-side backup.
3. Apply one small code change at a time in the GitHub branch.
4. Run PHP syntax validation on the entire resulting file, not just individual fragments.
5. Deploy with SHA256 compare-and-swap.
6. Check console HTTP status and render; if HTTP 500, restore backup.
7. Verify auto-update toggles against WordPress in both directions.

## Safety
Do not auto-update Elementor Pro or Essential Addons Pro as part of testing. Never expose pairing secrets in GitHub. The repository is public, so only commit nonsecret code.
