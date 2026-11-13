# Unique cookie report URLs and verified browser runtime

User asks for a unique hash URL per saved measurement, directly reopenable later, and actual cookie measurement. Continues authorized implementation without approval questions.

- [x] Add stable64-hex opaque report key derived from per-job secret hash, indexed digest in archive schema2. Return canonical report_url, read-only shared-report endpoint, private noindex/no-referrer result URL. Backfill legacy archived rows. Do not expose requester token, permit starting scans from shared URL, or create public listing.
- [x] Update history/address bar/share control/PDF with canonical ?mereni=HASH; preserve old fragment links. Test two jobs distinct, same job stable, valid/invalid key lookup, no secrets, legacy history, completed reopen.
- [x] Investigate sandbox probe: current traversal reads only main-thread /proc children; Linux children are per task and Firefox spawns on non-main threads. Test traversal across every thread while preserving UID/seccomp/capability requirements; deploy bounded probe once. No weakening sandbox.
- [x] If runtime verified, wire actual isolated browser scenarios and evidence. If not, preserve exact failure and no fabricated behavior/percent. Do not claim full completion.
- [x] Tests/review/deploy, direct uniqueURL reload/PDF verification, persist Git evidence and limitations.

Completed1.9.2. Runtime root cause confirmed: /proc children are per thread; scanning all thread children verified2 Firefox content processes with own seccomp filters. Actual isolated worker ran successfully twice. Final same-site measurement has11 actual tests,10pass/1mobile visibility issue,7 observations,4 screenshots. Canonical hash URL reopens saved result without owner credentials. Legacy fragment compatibility retained. See VERIFICATION.md.
