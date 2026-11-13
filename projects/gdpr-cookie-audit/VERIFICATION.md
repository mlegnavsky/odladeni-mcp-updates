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
