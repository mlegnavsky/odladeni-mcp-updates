# GDPR Cookies audit

User-approved scope: a free public URL scan on gdpr-cookies.cz, detailed readable results,
charts, screenshots when actually captured, cookie-policy comparison, downloadable Czech PDF.
WordPress integration must preserve the existing Elementor site and its restored availability.

Architecture: independent plugin, same-origin REST API and responsive dashboard, up to five
public HTML pages per audit. Server fetches are bounded and DNS-pinned to public addresses.
The basic engine inspects actual HTTP cookies, source integrations and linked policies.
It never equates source detection with execution or missing source text with a legal breach.
Interactive browser scenarios require an available renderer. An unavailable renderer is
explicitly unverified in both UI and PDF; no invented screenshots or consent results.

Preferred full engine: isolated browser worker behind an authenticated API. First inspect
hosting support. No paid account, new access grants, or unrelated services are authorized.
Fallback delivers the independently useful source/policy audit and identifies the remaining
browser-service requirement. Do not advertise the fallback as full interactive measurement.

Report: findings by severity and certainty, evidence, remediation, source URL; actual coverage,
provider/source chart, policy checks, cookies without stored values, export from measured data.
No automatic full-GDPR score. Render all remote content as text. No public scan history.
Rate-limit jobs per IP/domain and global concurrent work; expire jobs after one hour.

Deployment: versioned ZIP via Odladeni, install only gdpr-cookies.cz, wire existing tools
buttons to new shortcode page. Verify home, tools, scan, validation and downloaded PDF.


## Approved refinement 2026-11-13
Only cookies, cookie banner, consent behavior and the cookie-information page belong to this
audit. General privacy-policy compliance and generic controller/contact checks belong to a
future separate tool. Privacy-only links must not become cookie policy documents. Cookie
purpose, lifetime, provider, actual list and consent/change/withdrawal remain in scope.

Finish actual browser measurement when hosting permits: install verified official Node in
private vhost storage, official Playwright Chromium with browser sandbox enabled, and a local
DNS-pinning egress proxy rejecting private addresses on every request and redirect. No paid
services, exposed debugging port, authentication changes, or disabled sandbox. Bootstrap is
triggered only by authorized plugin activation; public scan requests cannot install software.
If dependencies or sandbox are unavailable, preserve accurate partial reporting and expose
the concrete blocker rather than fabricated results.

## Approved refinement: automatic cookie document checks

User requests no manual-verification step in cookie document results. Implement automatic discovery of linked cookie documents, context-aware comparison across their texts and explicit outcomes: information found, information not found in inspected documents, conflicting wording, runtime test unavailable. Every found/conflicting result carries the actual source URL/excerpt; missing information carries a concrete editing recommendation. A scan must never claim that an absent phrase proves a legal violation or that reading instructions proves actual consent behavior. Existing five-page cap, private-network restrictions, short report retention and cookie-only scope remain.

## Approved refinement: prominent documentation percentage

User asks for a detailed assessment with a percentage at the beginning. Display a clearly named cookie-documentation score: equal-weight six text checks and each observed service/cookie documentation match. Show numerator, denominator and all criteria. Missing public-document information is a failed criterion with a specific fix; it is not a generic manual-verification task. Inaccessible or not-yet-inspected input must not become fabricated zero/100 or a legal claim of absence. The separate browser/appearance/consent audit remains incomplete until the hosting prerequisite is resolved.

## Approved refinement: cookie categories and complete public declaration

User's screenshots demonstrate a CookieYes-injected21-entry declaration that rawHTML analysis missed. The scanner must discover its public data independently. Assess each declared category (necessary, functional, analytics, performance, advertisement, other) using explicit name/domain/lifetime/purpose/classification fields, show percentage/color and row-level reasons, sum all passed/total fields in the overview. This is documentation quality, not a claim that cookies were actually set. Source and retrieval failures must remain visible; dynamically unavailable content suppresses completed document scoring. Real consent/appearance tests remain pending a viable isolated browser host.
