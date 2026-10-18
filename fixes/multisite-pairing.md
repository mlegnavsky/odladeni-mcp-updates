# Multisite pairing fix — Odladění

## Problem
The current console `pairingSiteUrl()` accepts only two hard-coded domains. A third WordPress site is rejected before the pairing confirmation page.

## Required implementation
Replace the allowlist with a general, safe HTTPS site validator. Reject userinfo, custom ports, query strings, fragments, and non-root paths. Normalize the hostname (lowercase, trim trailing dot). Reject localhost, IP literals, private/reserved IPs, and DNS records resolving to internal addresses. Validate both IPv4 and IPv6 and enforce the same policy for outbound pairing requests, including redirects (disabled), to prevent SSRF or DNS rebinding.

Keep each connected site keyed by canonical site URL, with its own randomly generated shared secret. Never overwrite an existing shared secret on repeated pairing without an explicit authenticated rotation flow. Pair tokens must be short-lived and single-use.

## User flow
1. WordPress generates a short-lived pairing request and redirects to a standalone approval page.
2. If not signed in, choose a Google account via OAuth, preserving the pairing request securely in the session.
3. Show the target hostname and signed-in Google account. Require explicit confirmation.
4. Exchange the one-time token server-to-server and verify the response.
5. Redirect to the original WordPress admin page with a minimal status indicator, without exposing tokens or secrets in the URL.
6. Show a clear error and a retry action in WordPress if the exchange fails.
7. If the site is already connected, verify the actual bridge connection before reporting success; do not silently rotate its key.

## Validation
- Existing sites hyperkostka.cz and chcisvoje.cz continue to work.
- A third public HTTPS site can connect without code changes.
- Reused/expired tokens fail safely, without blank error pages.
- Two sites have independent keys and update/plugin actions remain site-scoped.
- Google login returns to the intended pairing flow.
- Confirm PHP syntax and smoke-test console before production deployment.

## Rollback
Use the Odladění server's atomic file backup and SHA256 compare-and-swap before deploying console/index.php. Do not touch installed WordPress plugins as part of this change.
