# Poslanecka-snemovna.cz native parity snapshot

This directory records the locally generated native Elementor design and its public media mappings. The JSON is a design source, not a catalog deployment certificate, live-data connection, or automatic proof of visual parity. The production native save receipt and the completed browser checks are recorded below, separately from reusable catalog certification.

## Files and dependencies

- `deployment/parity_convert.py`: conversion and measured refinements from the approved v17 hierarchy.
- `deployment/parity-design.json`: generated nine-part native candidate, including shared header/footer and seven content pages.
- `deployment/native-asset-map.json`: original source-to-WordPress-media mappings.
- `deployment/parity-asset-map.json`: approved icon/color role variants mapped to uploaded WordPress media.

The converter also needs sibling `deployment/native_convert.py`, `deployment/quality_convert.py`, and `deployment/live-unbound.json`, plus `v17/catalog-new.json` relative to the working directory. These are inputs, not optional runtime dependencies. `native_convert.py` is executed only up to its conversion-entry marker to load the parser and helpers; `quality_convert.py` is parsed with Python AST only to read the literal `copy_fixes` assignment. `live-unbound.json` supplies base part metadata and page mappings. All four supporting inputs are included in this snapshot, retaining the original relative directory layout.

Run from the project snapshot root, retaining the directory layout:

```sh
python3 deployment/parity_convert.py
```

This overwrites `deployment/parity-design.json` locally. Run conversion in a disposable copy when verifying a committed snapshot, then compare the resulting bytes/hash with the recorded design. The script does not itself save WordPress content or upload media.

## Native editing and media

Media IDs and URLs are specific to `https://poslanecka-snemovna.cz`. Verify existing attachments before reusing them there. For another installation, upload or resolve the actual media through the supported bridge and regenerate mappings; source-site attachment IDs do not identify attachments on another site. Do not silently replace missing assets with guessed IDs or placeholder URLs.

The design uses native containers and registered heading, text-editor, button, image, divider and Essential Addons Simple Menu widgets. `styles` is empty. Inline SVG geometries are represented by uploaded image assets rather than executable layout widgets. Site references are unbound from the existing analytical Kit so unrelated analytical global styles need not change.

Before saving, inspect the current active provider and actual registered controls, current document/root IDs and revisions, real WordPress menus, media and operation status. Use the supported native plan/apply workflow for existing pages and Header 76/Footer 86, preserving backups and compare-and-swap guards. See `docs/poslanecka-native-deployment.md` in the repository.

Responsive hide values must match the actual native export (`hidden-mobile`, `hidden-tablet` on this site), rather than assuming every switcher uses `yes`.

## Verification boundary

The static preflight, JSON parsing and converter syntax checks do not establish visual parity. The generated report intentionally leaves browser/import evidence unconfirmed until real checks are supplied. Verify the actual deployed desktop and mobile design against the approved reference, including the shared header/footer, logo link, native menu, lower sections, links, protected analytical routes and responsive photo/background inheritance.

The final native production save completed with operation `2682055aa3b611315ea12b5b08faede3`; see `deployment/final-live-receipt.json`. It updated seven existing content pages, shared Header 76/Footer 86, and menu 33. No documents were created in this final operation. A retained private backup and rollback are available through the supported native operation workflow.

Post-save native reads matched the returned revisions for all nine affected documents. The deployment operator also confirmed that active Kit 5, login 274, archive 1847, and legal documents 1831/1832 retained their protected revisions. These checks establish native data persistence and protected-resource preservation, not a comprehensive browser certification of analytical flows.

Production browser checks completed after the final native save. The homepage hero and card geometry matched the approved reference; native Header 76/Footer 86 rendered, including the desktop four-column footer. Results page 1928 loaded the static map attachment 1893. Preferences page 1931 retained the own-GRP/daily-model wording without presenting fabricated measurement graphs. Login page 274 rendered the existing Ultimate Member form 271 with username/password fields, and the logo link returned to the root URL.

At a 449-pixel mobile client width, there was no horizontal overflow, the hero photograph rendered with a measured hero height of 659.6979 pixels, and the hamburger menu opened and closed. These are the observed production browser checks, not an assertion that every analytical workflow or every content page was exhaustively exercised. The generated JSON's browser/import false flags are deliberately preserved because this work used ordinary native existing-site editing; it did not certify a reusable catalog import.

The obsolete preview page 1881 was moved to draft through separate completed operation `e5df1c6128e0680da4c9744bc3897d0e`.

A disposable rerun of `python3 deployment/parity_convert.py` reproduced `deployment/parity-design.json` byte-for-byte. Its SHA-256 is `3b91c748d42968b0728d0d54f2e71ba87e714799bce15b694ee025d99eda9929`. Every input and receipt hash is listed in `snapshot-sha256.json`. Static native preflight passed and continued to report visual/editor/import verification as false.
