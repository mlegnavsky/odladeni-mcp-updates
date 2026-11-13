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
