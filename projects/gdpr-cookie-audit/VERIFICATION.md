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


## Percentage and detailed documentation defects — 2026-11-13

Deployed final plugin1.5.2 only to gdpr-cookies.cz, SHA256:
2a5dc4acf882d9f3bb07fe606d291e546c39711c89504f309da9e07a46c386f8.
Final update backup: aa01875f1a44f25060660563dc99650a.

Prominent shared UI/PDF cookie-documentation score: six text checks plus each observed service and
cookie documentation match, each worth one point. Shows passed/total, all scored criteria and formula.
Missing content/conflicting wording now produce document errors with precise recommendations.
Missing content label: Chybí v dostupné dokumentaci. Scope is explicitly documentation; browser
behavior and visual contrast are not represented as measured. Full-website compliance is not scored.

96 PHP assertions and 20 Node tests pass. Independent review found two important score problems,
both reproduced RED then fixed: availability/truncation metadata now suppresses percent even when
all individual checks have known outcomes; a rounded partial score can never become 100. UI's full
completion wording independently requires passed===total. PHP/JS lint and whitespace check pass.

Live UI scan began2026-11-13T13:15:56Z (14:15:56 Europe/Prague): stranapro.cz completed5 pages,
allHTTP200, 3/8 document criteria passed ->38%. Main score visible, concrete errors reported.
Downloaded PDF parsed:38%, numerator/denominator, full criteria breakdown, missing-content label,
no manual-verification prompt. Seven pages. Final1.5.2 only changes title pagination; current renderer
built from that exact public report and first page visually verified, removing an orphaned heading.
Evidence: document-score-regression.json. UI screenshot captured during1.5.1; UI unchanged in1.5.2.

The source measurement engine and text rules retain the prior documented bounds. No Chromium
sandbox bypass or infrastructure changes. Browser consent/contrast measurement remains unavailable.


## Dynamic CookieYes declarations and categories — 2026-11-13

Deployed 1.6.3 only to gdpr-cookies.cz. ZIP SHA256:
e8cb90980d2f5a9e23311f5c5d52a36f369dfcfe6affc36acf9b3499c1cc33de.
Update backup: 23c59c4b834847fa3c38ac2fe85cb525.

Loads bounded public CookieYes script/banner/audit-table metadata without executing remote JS.
Strict provider/account resource allowlist, existing DNS-pinned transport, no redirects, bounded
accounts/resources/records/bytes/time. First-party account discovery only. Original provenance
preserved; cookie values never retained. Only matching mounted dynamic policy tables join evidence.
Unsupported/incomplete script shapes fail closed instead of silently omitting inventory.

Shared UI/PDF category scores assess five declared metadata fields per item: name, domain,
lifetime, meaningful purpose and known category. Category percentages sum exact passed/total
fields. Empty categories remain unscored. Overall public-evidence score sums documentation
checks and metadata fields; it is explicitly not consent behavior or legal compliance.

Real public source regression for stranapro.cz: 21 declared entries, necessary 5, functional 6,
analytics 2, advertisement 6, other 2. No performance entries. Four populated known categories
have complete metadata; other category is 60% (two missing purposes and unknown classification).
Declarations 101/105 = 96%; cookie documentation 6/8 = 75%; combined 107/113 = 95%.
These correct the prior source-only 38% result: CookieYes table was previously missing from
extracted text. The website itself was not changed. Google/YouTube/inventory/lifetimes now found.
Remaining document findings: no recognized withdrawal instructions and implied-consent wording.
This is a cached-public-source regression, not a final live browser scenario test.

Live public form and PDF/JSON export verified using gdpr-cookies.cz as the test target after
stranapro.cz reached the existing 5/hour domain limit. Limit was not bypassed. Live own-site report
contained 7 declared entries, category breakdown 3 necessary / 2 functional / 2 analytics. PDF
contains all seven names; source URLs, declaration basis and no cookie values verified. Eight
pages, category page visually inspected: no clipping/overlap. Overall percent correctly withheld
because policy document was not discovered (35/41 known partial points). UI expanded category
and both download buttons verified. 1.6.2 UI verified; 1.6.3 changes runtime probe only.

Final verification: 110 PHP assertions and 26 Node tests pass. PHP/JS package lint passed before
release. Review findings fixed: parser completeness, sandbox filter baseline, browser cleanup.

Browser measurement remains UNFINISHED. Private official Node/Playwright Firefox runtime can
render a trusted offline page, but content-process isolation could not be verified. Latest 1.6.3
probe: blocked / sandbox_unavailable / SANDBOX_CONTENT_NOT_FOUND, missing_libraries empty.
Do not infer the host universally forbids Firefox: this probe could not find a verifiable content
process. No --no-sandbox or other sandbox disabling. Runtime resources remain outside document
root, no system package installation. Full user-URL worker/scenario/contrast integration is still
needed after a verified isolated runtime is available; source/declaration analysis does not do it.
Further work requires actual server administration access or an authorized isolated worker host.


## Persistent history and useful missing-document diagnosis — 2026-11-13

Final release1.7.2, SHA25630ad5c8428879ac81f56d3a359c287402f5b4e6beee5fe9e8bff5910ad90b82b.
Final update backup5f054e429c00d37dbcf56cb01cd24216. Active on gdpr-cookies.cz only.
Private wp_gca_measurements snapshots save every start/step, including failed/running jobs.
Database save failure returns503; transients are cache only. Capability token remains required,
stored hashed on server; no public archive listing or requester IP history. Visitors keep bounded
local references and private fragment links; administrator has Tools > Měření cookies, paginated
25 per page, with capability-protected report viewer/download. Admin UI access requires login;
its rendering was reviewed in code, not verified through a logged-in live browser this turn.

Old surviving WP transient jobs are imported in bounded20-row cron batches. INSERT IGNORE and
no migration cache write ensure a concurrent step wins; report promotion uses same insert-only
path then reads durable snapshot. Deleted/expired-and-removed old data cannot be recovered.
Public status reports archive_ready and archive_import_complete; schema creation confirmed live.

Root cause of user's spd.cz screenshot: footer Pravidla Cookies href="#" was silently discarded;
no policy yielded unexplained unknown checks. Now named placeholders produce precise errors,
and no-document in successfully inspected readable pages gives0/6 documented checks (0%).
Scope explicitly names inspected pages; does not assert global/legal nonexistence. HTTP200
challenge pages, empty JS shells, fetch failures and unsupported query/modal links suppress
percent instead of generating false zero. Actual browser/consent/contrast remains unavailable.

Live1.7.1 public form scan of SPD.CZ normalized to https://spd.cz/, completed5 HTTP200 pages,
one source Google tag integration, five placeholder policy links, no cookie document, score0/6.
Saved ID4657912fbca60a1ba285cc649a7cb3f5. Reload restored identical ID/result without starting
another measurement. JSON and PDF downloaded: matching ID,target,persistent flag, score0%; no
queue/token_hash/IP fields. PDF first page rendered and visually verified. Second live target
https://gdpr-cookies.cz/ saved distinct ID3f31117c605e3630b81e9c4cfc819d26 and85% available
public-evidence score (7 declared entries, missing document). Both history entries visible; clicking
SPD entry reopened its original ID/score after second scan. Screenshot evidence/history-spd-v1.7.1.jpg.
This does not represent85% consent compliance; runtime test remains explicitly unperformed.

Review: two false-zero findings reproduced/fixed (challenge/shell, unsupported query link).
Additional migration review found stale snapshot REPLACE race; test injected newer concurrent
save and reproduced rollback RED, then passed with insert-only import. Full final suite142 PHP
assertions (includes7 rerun WP assertions via archive test),29 Node tests. PHP/JS package lint
and git diff --check pass. UI assets identical between tested1.7.1 and final1.7.2 except version.

Final live status (cache-busted GET) confirms1.7.2, archive_ready:true and archive_import_complete:true at2026-11-13T15:09Z. Bare status URL had an older cached1.7.1 response; final verification used explicit cache bypass.


## Unique report URLs and actual isolated browser audit — 2026-11-13

Final deployed1.9.2 SHA256a6385e0f798ca1f1247c61f5de8a1d94d7b7b80fab7137722bfa357600951991.
Final update backup e2aa5587123cba6b103742f8ed33cbd3. Active only on gdpr-cookies.cz.

Every report now has canonical /kontrola-cookies/?mereni=<64hex> URL. Opaque key is HMAC
of measurement ID with its stored per-job secret hash; lookup uses indexed SHA256 digest.
Schema2 verifies column AND index before marking migration complete. Bounded backfill for
archived reports confirmed live. Owner token remains distinct; shared-report route only reads,
never starts or resumes a scan. All audit pages send no-referrer/no-store; result URLs additionally
send X-Robots-Tag:noindex,nofollow (SEO plugin's HTML robots alone did not honor wp_robots).
Old owner fragments still work. Failed local storage preserves owner fragment for resumability;
share control/PDF expose canonical read-only URL. Key values deliberately omitted from Git.

Resolved runtime blocker, not by reducing security: original probe read only the main thread's
/proc children. Firefox spawns on other threads. Traversal now enumerates every task's children.
Live probe verified2 content processes, same nonrootUID, NoNewPrivs1, CapEff0, Seccomp2,
and filter count above Node's inherited baseline. Firefox default content sandbox remains enabled.

New detached worker verifies sandbox before navigating untrusted URLs, through existing pinned
public-only proxy. WebRTC disabled; service workers blocked; arbitrary schemes/credentials/ports
rejected; per-proxy50MB transport and connection limits. One browser job at a time via owner-bound
atomic DB lock;145s independent process-group kill deadline includes graceful cleanup. Private
job files outside document root. Asynchronous PHP polling/cron collects progress into permanent
archive. Source-only failures remain failures; browser failures retain valid source results aspartial.

Real scenarios: pre-choice, reject, reload/persistence, accept, withdraw, analytics-only,
marketing-only, mobile390x844, desktop text contrast, storage names, observed Consent Mode v2
calls. Known CookieYes/Cookiebot/OneTrust/Complianz controls plus bounded exact-text fallback.
Unsupported/unverifiable interactions remain unknown, not fabricated passes. Cookies/requests
are observed rather than inferred from source. Cookie/storage values not persisted; request query
values stripped except boolean recognized denied signal. Consent checks compare category states,
not cookie timestamps. Cookie documentation is re-compared against actual observed cookie names;
explicit documented wildcards match generated GA4 names. Technical score displayed separately
from documentation/declaration score, with exact passed/total/unavailable counts.

Live1.9.0 and1.9.1 scans of https://www.stranapro.cz/ each completed5 source pages and actual
Firefox scenarios. Distinct IDs f02968e295ab6dc4e1a9a353588bb3f4 and f1363c59ade345eeb7310ab9f9d72db9,
distinct opaque URL hashes for same site. Both archived and reopenable; final1.9.1 result opened
through shared-report POST without owner token, then browser reload restored identical ID.
Final report:11 tests,10pass/1error (mobile banner not visible),0unfinished.7 observation states,
4 JPEG screenshots. Before/reject/reload: cookieyes-consent only. Accept/analytics-only: also
_ga_0SB20H9LHP and_ga. Withdraw/marketing-only: consent cookie only. Actual three cookie names
matched documented inventory, including_ga_* wildcard. Consent Mode four states denied before,
granted after. Desktop5 contrast samples passed. Mobile screenshot shows notification prompt and
no recognized cookie banner; final rule reports that measured visibility issue explicitly.

PDF downloaded for final result, all actual cookie names, persistent URL,91% technical score and
mobile finding verified.24-page1.9.0 export first page and screenshot page rendered/visually checked;
final1.9.1 export parsed and includes revised findings. Final1.9.2 only balances UI cards and colors.
Browser verified final1.9.2 restored report, balanced cards and phase counts; proof screenshot
in evidence/browser-results-v1.9.2.jpg. No raw report, owner credential, share key or full source
page copies are committed.

Reviews: URL referrer leakage, schema-failure flag and blocked-storage resume reproduced/fixed.
Worker review findings reproduced/fixed: timestamp-only withdrawal, unverified exclusive-category
choices, HTTP-denied/empty pages producing passes, omitted aborted tests/hidden reason, and
shutdown defeating deadline. Added forced SIGKILL child-process test. Browser-cookie reassessment
keeps external document integrations outside target service scope. Final full suite166 PHP assertions
(includes14 repeated WP assertions across archive/browser tests),41 Node tests passed. PHP/JS package
lint and diff --check passed. Runtime/browser measurement is now working; prior blocked entries
above describe historical releases, not final state. Scope remains bounded snapshots and recognized
controls, not an exhaustive legal certification.

## Visual report 2.0.1 — 2026-11-13
Deployed ZIP SHA256 3ff2742549446cf42c3af8f61cb6715ff404d53ebf857cd04f2cc0011ed29b71; bridge confirmed active 2.0.1 with reversible backup.
Server-owned public presentation whitelist now excludes raw inventories, policy evidence, fixes, network requests, declaration payloads and diagnostics across start/step/report/shared-report. Admin-report uses manage_options and WP REST nonce authentication; anonymous live request returned401. Internal JSON/PDF controls require administrator result. Full record remains in archive.
Public PDF is3 A4pages: overview/priority cards, categories/phase chart, thumbnails/scope. Actual UI download verified as valid3-page PDF, Czech text, correct73% score, all four area scores and four screenshots. Desktop result, zoom-and-close evidence modal and comparison of two saved same-domain measurements visually verified in browser. CSS has390px responsive rules, but separate mobile viewport visual inspection was unavailable in this execution environment; do not describe that as visually verified.
Fresh live stranaPRO scan completed11 browser tests,0unfinished,7 distinct cookie/storage items,4screenshots,73% overall (documentation66, behavior100, metadata/classification76, appearance50). Its unique hash URL reopened the same saved result. Existing archived result also reopens with new presentation; its measured snapshot is unchanged.
Scores mean doložená kritéria, not legal compliance. Four areas equally weighted, floor rounding. Missing expected scenarios or observations block overall completion. Unknown purpose earns no classification point. Known category mismatches and clear measured/declaration expiry discrepancies remain in internal evidence. Name-based recognition is bounded and cannot establish every purpose. Unknown storage retention is not presumed correct.
Fresh independent review found false completion from omitted scenarios, minute/day retention tolerance, misleading conflict wording and fixed PDF-row overflow. All fixed with regression checks. Tests:190 PHP assertions,41 Node tests, PHP/JS package lint and git diff--check passed. Native git push lacked HTTPS credentials; source preservation uses connected GitHub API instead.
