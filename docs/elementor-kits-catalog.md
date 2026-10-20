# Odladění – katalog Elementor Template Kits

Účel: indexovat existující ZIP kity v `/kits/` bez změny originálů. Údaje o dvou kitech pocházejí z předchozí analýzy; úplná inventura serveru zatím neproběhla.

## Ověřované referenční kity

| Kit | Šablony | Widgety | Využití | Omezení |
|---|---:|---:|---|---|
| Aho Agency & Business | 19 | 581 | agentury, firemní weby, služby | některé části Elementor Pro |
| Coestra Political Party & Candidate | 15 | 328 | politické weby, kandidáti | Jeg Elementor Kit, MetForm, Header & Footer Builder |

## Architektura

1. **Originály**: ZIP archivy beze změn, pouze čtení.
2. **Rejstřík**: JSON metadata z každého ZIPu (soubory, stránky, widgety, závislosti, styly, počet kontejnerů).
3. **Ověřené sestavy**: až po testu kompatibility Elementor Free + Essential Addons a editovatelnosti ve WordPressu.

## Pravidla výběru

- Upřednostnit moderní container/flexbox struktury, responzivní nastavení, globální barvy a typografii.
- Zvlášť označit Elementor Pro widgety a chybějící pluginy; neoznačovat je za Free-kompatibilní bez testu.
- Header/footer vždy globální a opakovaně použitelný.
- Neinstalovat cizí kit ani plugin na produkční web bez testu a zálohy.
- Nespoléhat na názvy ZIPů: použít skutečný obsah JSON manifestů a šablon.
- Vést původ, SHA-256, závislosti a výsledek importního testu.

## Další krok

Spustit `python3 scripts/index_elementor_kits.py /cesta/k/archivum --output kits-catalog.json` v prostředí, kde jsou ZIPy fyzicky dostupné. Nevyžaduje přístup k WordPressu ani zápis do archivů.
