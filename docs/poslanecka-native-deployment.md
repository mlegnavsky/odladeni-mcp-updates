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

Do not assume a license warning means the provider is active, and do not rewrite display conditions without evidence that they changed. Current bridge snapshots omit `_elementor_conditions` and template taxonomy; `content-preserve.php` preserves node settings only. The legacy `odlContentCheckSharedParts` guard checks the `hk4` renderer, not Elementor Pro conditions. A future conditions-preservation adapter requires explicit snapshots, verification and tests; this record does not claim that gap is fixed.

## Durable bridge fixes released 2026-11-08

Only narrow patch fragments and regression scripts are committed. Full plugin entry files, embedded license values, credentials and private backups are excluded.

| Release | Fix | Verified evidence |
| --- | --- | --- |
| 3.1.14 | Permit WordPress entity-normalization equivalence only in post-title readback verification. Other fields, raw revisions, backups and CAS remain exact. | Stored release validation accepted, including PHP lint. Recovery operation `32cc73c4847e9cab2351c2d0dfc11283` completed; the original Kit revision `bd919bf218da4662d0067299227315df0495fc233c8cbba0723c26797579a638` was restored. |
| 3.1.15 | Allow native `z_index`, `text_padding` and image `link_to`, with finite bounded number, dimension-object and strict enum validation. | Stored release validation accepted, including PHP lint. Actual capabilities exposed container `z_index`, button `text_padding` and image `link_to`. Registered per-element controls and select options remain authoritative. |

Release ZIP SHA-256 values:

- 3.1.14: `02e1f0033b3261bdcd7a02e5e6773bb2889209ad7a40c82b93828f3ce0a9bef3`
- 3.1.15: `04aac4367c76913fabce6d4aaded4b79559a6bb3599e348ae24ac007675ebc7d`

Patch/replacement fixtures are under `scripts/patches/`. Apply only to the stated source release with exact old-text matches, create a new version and run server release validation; do not weaken MCP or catalog gates. PHP regression scripts under `scripts/tests/php/` require a WordPress test bootstrap with the patched native content service loaded. They contain eight title cases and seventeen setting cases plus three provider rejection cases. They were prepared but **not executed locally**, because this workspace has no PHP binary. Server PHP lint and live recovery/capability evidence are separate from those unexecuted assertions.

## Catalog importer remains a separate route

The authenticated reusable-template deployment interface is virtual file `templates/_deploy.json`, discovered by `file_list({file:"templates"})`. Read its current SHA-256 and protocol, the exact design ID/revision and `fleet_list_sites` URL. Its `start` command requires full current Codex checks; subsequent `step` commands use the returned job ID/revision. Unknown results require `status`, not a fresh start. Restore remains a separately authorized action with verified backup and CAS.

The original 1.7 template package is not portable: shared parts exist only in a child theme, with external CSS and HTML layout widgets. Native import rejects nonempty `styles`, unadapted HTML/shortcode widgets and unsupported theme page settings. The current creative audit also requires reduced-motion CSS inside `styles`; that conflicts with the importer and remains unresolved by the two narrow bridge fixes above. Bundled runtime motion CSS is opt-in, so empty `styles` alone does not prove a fallback is active. Do not forge review evidence or claim reusable catalog import certification.

Run the static preflight before presenting a reusable package as installable:

```sh
PYTHONPATH=scripts python3 -m unittest discover -s scripts/tests -p 'test_native_design_preflight.py'
python3 scripts/check_odladeni_native_design.py exact-design.json
```

## Current verification boundary

Native content operation `306859bf0376b776a6845d8d056aad5f` completed, including pages and existing Header 76/Footer 86. The user then reported a visual mismatch; further parity work is in progress. This document records proven infrastructure fixes and the correct route. It does not claim visual parity is complete, protected analytical flows have all been retested, or the reusable catalog importer has been verified.
