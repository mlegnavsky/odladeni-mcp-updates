# Administrator municipality directory

User requests all Czech municipalities in gdpr-cookies.cz administration, preferably reused from Poslanecká sněmovna project. Implement one private Tools → Obce ČR page, manage_options only, with name/code search, region/district filters,50 rows per page and precise source date. Reuse only municipal names/codes and geographic hierarchy from verified ps-precinct-registry1.19.3 units.jsonl snapshot2026-09-08.6258 municipality-level records contain four military territories; exclude their exact codes, yielding6254 municipalities. No precincts, wards, political data, personal data, guessed websites or live dependency on source site. Bundle compact PHP-guarded data to preserve private interface and reproducibility.

- [x] Generate reproducible minimal dataset from SHA256-verified release; validate6254 unique six-digit codes,14 regions, all parent relationships and exact exclusions.
- [x] Add failing tests for completeness, disambiguation, accentless search, code search, combined filters, invalid inputs, pagination and admin capability gate.
- [x] Implement pure filtering/pagination plus native WordPress admin page. Escape every input and output. GET filters only, no write endpoint or public shortcode/API.
- [x] Run relevant/full checks, package, deploy only gdpr-cookies.cz, verify installation/data and persist Git. Source Poslanecká sněmovna remains unchanged.
