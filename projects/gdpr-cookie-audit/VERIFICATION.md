# Verification 2026-11-13

Plugin version 1.1.1 deployed only to https://gdpr-cookies.cz.
Release SHA256: 87ccba946ee504f8aaf4b2b97d288ed2c95117a9a0513b0181883cf942dbcced.

- Core PHP tests: 34 assertions passing, including private/reserved addresses, credential URLs,
  relative URL resolution, policy discovery excluding hostname, redirect cookie provenance,
  discarded cookie values and document evidence.
- WordPress adapter tests: 7 assertions passing (expiry, token isolation, origin, locks, redaction).
- Node tests: 5 passing, including source evidence versus consent violations, external policy
  isolation, partial failures, cookie deduplication and real PDF export.
- Independent code review: no critical findings; important policy-discovery and redirect-cookie
  issues reproduced and fixed with regression tests. Root URL, session expiry, lock re-read,
  external-doc evidence isolation and PDF source URLs also addressed.
- Browser: real scan of own website plus explicit cookie-policy URL, 5 HTML pages, one known
  source integration, policy text comparisons. PDF downloaded through public UI and parsed:
  7 pages, Czech characters, URLs and methodology present.
- Initial browser inspection found inherited dark-body contrast problem; corrected in 1.1.1.

Limitations: browser execution engine is not installed on the hosting (fixed Node/Chromium
probes returned no executable). Source audit and policy analysis are live. Interactive consent
scenarios, JavaScript cookies/localStorage, screenshots, timeline of real network execution,
and mobile banner measurements remain explicitly unverified in UI and PDF. They are not
represented as passing or as completed features. No full GDPR compliance score is emitted.

The content bridge returned a memory error after applying the page publication and tools link.
Fresh reads verified both actual changes. The operation receipt remains pending, so it is not
used as a successful transaction receipt. Existing page backups remain at the bridge.


## Cloudflare IPv6 regression fix — 2026-11-13

User-reported stranapro.cz was wrongly rejected as a private network. DNS returned public
Cloudflare A addresses 104.21.40.189 / 172.67.156.78 and AAAA addresses in 2606:4700::/32.
The extra IPv6 guard required a literal first byte 0x20 (2000::/8) instead of global unicast
2000::/3. Reproduced old rejection for Cloudflare ranges. Corrected the prefix mask, retaining
private/reserved validation and explicit documentation ranges 2001:db8::/32 and 3fff::/20.
Independent focused review confirmed no demonstrated internal-network SSRF path; the review's
3fff documentation-range finding was addressed before deployment.

Live follow-up also identified /cdn-cgi/l/email-protection being crawled as a content page;
Cloudflare technical /cdn-cgi/ paths are now excluded. Production plugin 1.1.4 deployed.
ZIP SHA256: 4865b46a3d80885eabfaf85846d1880887b0c127ac63683447c5c8f8cfe6f16b.

Regression verification: 50 core assertions, 7 WordPress assertions, 5 Node tests all passing.
Tests cover all seven published Cloudflare IPv6 prefixes, nonpublic/documentation addresses,
and excluding the Cloudflare email protection endpoint from content-page discovery.

Final live regression: stranapro.cz completed; five public pages including /pravidla-cookies/ returned HTTP 200, zero fetch errors. See stranapro-regression.json.


## Cookie-only scope and browser viability — 2026-11-13

Deployed 1.3.6 to gdpr-cookies.cz only. Release SHA256:
e90420e6bdb2f544d8c914fa7dc1890eefb2a834739b41843fda44abdc6f9582.
Release ZIP manifest predates the final documentation-only changelog notes.

Cookie documents are discovered separately from general privacy documents. Generic controller/contact
checking is removed, including filtering legacy report fields in the dashboard/PDF. Cookie checks:
purposes, meaningful lifetime evidence, withdrawal, rejection, specific inventory, implied-consent wording.
Known cookie scanner/settings links no longer displace the actual cookie document. Empty browser
results now produce one unverified finding and a compact notice, not seven placeholder cards.
Observed-result rendering is prepared but no browser worker is connected.

Validation: 56 PHP core assertions, 7 WordPress adapter assertions, 9 runtime assertions and 14 Node
tests passed. Public UI scan of https://www.stranapro.cz/ completed at 2026-11-13T12:20:10Z:
five HTTP200 HTML pages, /pravidla-cookies/ found automatically, 2 source integrations, no load errors,
no generic controller/contact finding. Downloaded real Czech PDF: six pages; cookie policy URL,
lifetime, withdrawal, cookie-only scope and unmeasured-browser explanation present.

Runtime attempt: official SHA256-pinned Node 22.23.5 and Playwright-core1.65.0/Chromium installed
outside the document root, system library packages downloaded from host APT repositories and extracted
privately (no system installation/root). First launch identified missing direct libraries, then three
transitive libraries; these were added. Final live status at 2026-11-13T12:22:06.940Z:
state=blocked, reason=sandbox_unavailable, missing_libraries=[], browser_audit=false.
Hosting does not permit the required Chromium sandbox. No --no-sandbox fallback is used. A hosting
administrator must enable supported sandbox isolation or provide an isolated browser worker host.
The current tool does NOT measure consent actions, JavaScript cookies/storage or real screenshots.
The proxy and scenario-logic modules are tested preparation, not a working browser measurement service.
No scanner result is labeled as browser-verified. Source/policy auditing remains available.

Independent review found no critical issue. Canonicalized real paths now protect runtime storage
against public-root symlinks; additional native libraries were identified through actual ldd output.
Remaining discovery limitation: nonstandard cookie-document labels may require manual URL input.


## Automatic cookie document assessment — 2026-11-13

User requested automatic cookie-policy checking rather than generic manual-verification prompts.
Deployed version 1.4.1 to gdpr-cookies.cz only. Release SHA256:
1f5ebe1293e27d360434abf87fc822c212619f20af0626b67615ba490bc0c306.
Backup before final update: 2775764611de078f2d0c36041155a82f.

Implementation: preserve document blocks/table rows, discover nested cookie documents within the
existing five-page cap, combine evidence across documents with exact source URLs. Checks distinguish
found/missing/conflicting text and unavailable content. Missing information gets a specific editing
recommendation in UI/PDF. Generic provider names no longer prove documentation of a specific service.
No website content is rewritten by scanning. No browser runtime or system permissions changed.

Regression suite: 23 automatic-policy assertions, 56 core assertions, 9 runtime assertions,
7 WordPress adapter assertions, 15 Node tests all passing. PHP/JS lint and git diff whitespace check pass.
Independent review identified three important groups; each reproduced RED then fixed and verified:
- General files/GDPR consent incorrectly counted as cookie evidence: narrower context and explicit exclusions.
- Legitimate negative consent wording erased instructions: negation attached to prohibited action only.
- Unavailable/empty documents became positive absence checks: availability/coverage now prevent passing them.
Additional regression: session in a cookie name is not lifetime evidence; redirected roots deduplicate;
long evidence stays bounded around the match; provider Google alone cannot prove YouTube/Maps/Analytics.

Public UI final regression started 2026-11-13T12:44:49Z (13:44:49 Europe/Prague): five pages completed,
zero fetch errors; rules at /pravidla-cookies/ auto-discovered. Positive purpose/rejection evidence,
missing lifetime/withdrawal/inventory information, implied-consent conflict, YouTube source/document
mismatch. UI uses exact outcome labels and recommendations, no 'Ověřit ručně' prompt. Real six-page
PDF downloaded, parsed and checked for outcome labels, recommendations, cookie-only scope and no
manual-verification prompt. Sanitized evidence summary: automatic-policy-regression.json.

Limits remain explicit: deterministic language rules identify evidence in at most 150kB text per cookie
document; missing information is scoped to inspected accessible content, not asserted as a legal breach.
Empty/blocked/incomplete documents cannot produce a passing absence result. Interactive cookie actions,
JS storage and banner screenshots still require a working isolated browser host; the existing hosting
continues to block Chromium sandbox (confirmed before this change). This release automates document
assessment, not end-to-end consent scenarios or a complete legal certification.
