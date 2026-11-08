# Poslanecka-snemovna.cz: native design changes through Odladění

## Existing-site content workflow

The paired Odladění bridge is authenticated and writable. Browser login and a separate website Git repository are not prerequisites. Match the exact HTTPS site URL with `fleet_list_sites` before reading or writing. The user approved deployment of the approved appearance; an accepted native plan alone does not satisfy that visual requirement.

For an existing site whose shared templates are already assigned, use the ordinary native content workflow. This is an existing-site edit, not certification of the reusable catalog importer.

1. Read current documents, active Kit, real WordPress menus, provider capabilities and revisions. Inspect `view:capabilities, search:theme_builder` before editing shared templates. Require the provider that actually renders their document types to be active.
2. Preserve the active analytical Kit unless an explicit change requires it. Locally unbind design references and use registered native controls for this design rather than changing unrelated global styles.
3. On this site, Header **76** and Footer **86** are existing `elementor_library` documents. Replace their exact root section/container with `section_replace`, retaining their document identities, types and assignments. `document_replace` is page-only; `template_update` changes title/status rather than the native tree. Read root element IDs and revisions again before planning.
4. Use `document_replace` for existing pages and `page_create` for genuinely new pages. Bind native menu widgets to real WordPress menus and maintain actual menu items/URLs. Do not substitute HTML menus or custom layout CSS for native controls.
5. Submit `fleet_content_plan`, then save with the returned exact `plan_id`, `plan_hash` and a stable request ID through `fleet_content_apply`. A plan does not save. Inspect the operation after an uncertain result before retrying; never create a second request ID to resolve uncertainty.
6. Retain verified private backups and byte-exact revision/CAS guards. On partial failure, inspect the operation, restore through the supported rollback workflow, and retain the same recovery request ID and original expected-revision map when continuing an existing rollback.
7. Inspect real desktop, tablet and mobile rendering against the approved reference, including full-page composition, header/footer, lower sections, responsive layout, typography, logo link, menus, touch/focus states, reduced motion and no-JavaScript availability. Check editor editability and real saved native data. A `done` receipt proves native data persistence, not visual parity.
8. Preserve login, legal documents, users, election databases and analytical plugins. Check their actual routes and protected behavior. Do not deploy a demo cookie-consent dialog or replace existing legal text with draft template text.

## Provider dependency incident

The redesigned Header 76 and Footer 86 remained published and retained their native types, but the public site temporarily rendered Hello Elementor defaults. Fresh `theme_builder` inspection showed `pro_active:false`, no registered header/footer document types and no conditions API. Plugin inventory confirmed installed Elementor Pro 4.2.3 was inactive. Reactivating that installed provider restored both template IDs in the live DOM.

Use canonical responsive values from actual native exports or registered controls. The original Header 76 export used `hide_mobile:'hidden-mobile'` and `hide_tablet:'hidden-tablet'`; writing `yes` does not produce those classes. Release 3.1.16 does not hardcode a generic switcher enum in `odlContentControlValue`; no switcher-validator change or release 3.1.17 is needed for this correction.

Do not assume a license warning means the provider is active, and do not rewrite display conditions without evidence that they changed. Current bridge snapshots omit `_elementor_conditions` and template taxonomy; `content-preserve.php` preserves node settings only. The legacy `odlContentCheckSharedParts` guard checks the `hk4` renderer, not Elementor Pro conditions. A future conditions-preservation adapter requires explicit snapshots, verification and tests; this record does not claim that gap is fixed.

## Durable bridge fixes released 2026-11-08

Only narrow patch fragments and regression scripts are committed. Full plugin entry files, embedded license values, credentials and private backups are excluded.

| Release | Fix | Verified evidence |
| --- | --- | --- |
| 3.1.14 | Permit WordPress entity-normalization equivalence only in post-title readback verification. Other fields, raw revisions, backups and CAS remain exact. | Stored release validation accepted, including PHP lint. Recovery operation `32cc73c4847e9cab2351c2d0dfc11283` completed; the original Kit revision `bd919bf218da4662d0067299227315df0495fc233c8cbba0723c26797579a638` was restored. |
| 3.1.15 | Allow native `z_index`, `text_padding` and image `link_to`, with finite bounded number, dimension-object and strict enum validation. | Stored release validation accepted, including PHP lint. Actual capabilities exposed container `z_index`, button `text_padding` and image `link_to`. Registered per-element controls and select options remain authoritative. |
| 3.1.16 | Allow native text-editor `paragraph_spacing` as a finite nonnegative dimension from 0 to 1000, with px/em/rem units and the existing dimension-shape validation. | Stored release validation accepted, including PHP lint. Registered per-widget control validation remains required; no custom CSS is introduced. |

Release ZIP SHA-256 values:

- 3.1.14: `02e1f0033b3261bdcd7a02e5e6773bb2889209ad7a40c82b93828f3ce0a9bef3`
- 3.1.15: `04aac4367c76913fabce6d4aaded4b79559a6bb3599e348ae24ac007675ebc7d`
- 3.1.16: `c14a31e5d15ff14bbb3f6d515905517c1ce77686e3dd91506e21eb5c702ab0dd`

Patch/replacement fixtures are under `scripts/patches/`. Apply only to the stated source release with exact old-text matches, create a new version and run server release validation; do not weaken MCP or catalog gates. PHP regression scripts under `scripts/tests/php/` require a WordPress test bootstrap with the patched native content service loaded. They contain eight title cases, seventeen core-setting cases plus three provider rejection cases, and seventeen paragraph-spacing boundary/type cases. They were prepared but **not executed locally**, because this workspace has no PHP binary. Server PHP lint and live recovery/capability evidence are separate from those unexecuted assertions.

## Catalog importer remains a separate route

The authenticated reusable-template deployment interface is virtual file `templates/_deploy.json`, discovered by `file_list({file:"templates"})`. Read its current SHA-256 and protocol, the exact design ID/revision and `fleet_list_sites` URL. Its `start` command requires full current Codex checks; subsequent `step` commands use the returned job ID/revision. Unknown results require `status`, not a fresh start. Restore remains a separately authorized action with verified backup and CAS.

The original 1.7 template package is not portable: shared parts exist only in a child theme, with external CSS and HTML layout widgets. Native import rejects nonempty `styles`, unadapted HTML/shortcode widgets and unsupported theme page settings. The current creative audit also requires reduced-motion CSS inside `styles`; that conflicts with the importer and remains unresolved by the narrow bridge fixes above. Bundled runtime motion CSS is opt-in, so empty `styles` alone does not prove a fallback is active. Do not forge review evidence or claim reusable catalog import certification.

Run the static preflight before presenting a reusable package as installable:

```sh
PYTHONPATH=scripts python3 -m unittest discover -s scripts/tests -p 'test_native_design_preflight.py'
python3 scripts/check_odladeni_native_design.py exact-design.json
```

## Current verification boundary

Final native content operation `2682055aa3b611315ea12b5b08faede3` completed after the initial visual mismatch and native refinements. Its receipt and reproducible design snapshot are under [projects/poslanecka-snemovna/native-parity](../projects/poslanecka-snemovna/native-parity/README.md). It updated seven content pages, Header 76, Footer 86 and menu 33; fresh native document revisions matched the returned receipt. Active Kit 5, login 274, archive 1847 and legal documents 1831/1832 retained their protected revisions.

Production browser checks subsequently confirmed the homepage hero/card geometry, native Header 76/Footer 86 and desktop four-column footer; results page 1928 and static map 1893; preferences page 1931 with own-GRP/daily-model wording; existing Ultimate Member login form 271 on page 274; and logo navigation to the root URL. At a 449-pixel mobile client width, the hero photograph rendered, hero height measured 659.6979 pixels, no horizontal overflow was present, and the hamburger menu opened/closed. The obsolete preview page 1881 was moved to draft through completed operation `e5df1c6128e0680da4c9744bc3897d0e`.

These are observed native-save and production browser checks. They do not claim exhaustive exercise of every protected analytical workflow or every content page. The saved design preserves its false browser/import verification flags because it was applied through ordinary native existing-site editing; reusable catalog certification remains separate.
