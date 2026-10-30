# Odladění: schvalování uživatelů a propojení ChatGPT

Zadání: nové uživatele osobně schvaluje superadministrátor. Přihlášení přes Google nesmí samo přidělit přístup. Správa uživatelů a průvodce připojením musí být viditelné také superadministrátorovi.

Používáme existující PHP konzoli, SQLite, Google OAuth a existující MCP OAuth s obnovovacími tokeny. Nevytváříme druhou evidenci webů ani vlastní chat. Samotný Google login nepřidává plugin do ChatGPT.

## Implementace
- `assets/accounts.php`: jednorázová migrace `approval-v1`, před ní privátní záloha SQLite; všichni dosavadní uživatelé mimo superadmina čekají na schválení. Nové Google účty mají `enabled=0`. Rozhodnutí superadministrátora a zneplatnění tokenů jsou atomické a auditované.
- `console/accounts.php`: seznam žadatelů před ostatními, schválení, zamítnutí, blokace, přidělení webů pouze aktivním uživatelům; přehled vlastních živých obnovovacích autorizací bez tokenů nebo jejich identifikátorů.
- `console/index.php`: čekací obrazovka po ověřeném Google loginu; počet žádostí v navigaci. Dosavadní superadministrátor zůstává povolený.
- `console/accounts-oauth.php`: souhlas s propojením až po schválení účtu.
- Existující kontrola `enabled` chrání konzoli, přidělené weby i MCP a obnovu tokenů. Přístup na web vyžaduje navíc samostatné členství.

## Omezení distribuce
Ve vyhledávání veřejného katalogu nebylo Odladění nalezeno. Odkaz proto otevírá skutečný katalog https://chatgpt.com/plugins s návodem; nepředstírá přímou instalaci. Pro jednoduché připojení všech klientů je třeba skutečně zpřístupnit distribuci pluginu. Nový zákaznický Google/ChatGPT účet zatím nebyl použit k ostrému testu.

## Test a nasazení
1. Spustit `tests/accounts-approval.php` a `tests/accounts-oauth.php` v PHP s PDO SQLite.
2. PHP lint všech čtyř změněných souborů.
3. Nasadit nejprve sdílenou politiku, potom konzoli; použít porovnání původního SHA256 a automatickou zálohu.
4. Znovu načíst soubory a ověřit shodu SHA256, dostupnost MCP a veřejné konzole.
5. Při návratu obnovit soubory ze záloh. Databáze má samostatnou privátní zálohu; nevracet ji slepě po nových registracích. Bezpečné zachování blokovaných účtů má přednost před automatickým obnovením přístupu.

Změna je vydání konzole `accounts-approval-2026-10-30.1`; MCP zůstává 2.6.2. Žádný WordPress plugin ani klientský web se neaktualizuje.
