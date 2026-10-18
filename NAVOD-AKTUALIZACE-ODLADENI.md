# Odladění – trvalý návod k aktualizaci (18. 10. 2026)

## Kde co běží
- Produkční MCP: https://plaginy.odladeni.cz/mcp/ (MCP služba, hlášená verze 2.6.1).
- Správcovská administrace: https://plaginy.odladeni.cz/console/; soubor `console/index.php`.
- GitHub: https://github.com/mlegnavsky/odladeni-mcp-updates, větev `main`.
- Ověřená cesta pro celé MCP soubory: `https://raw.githubusercontent.com/mlegnavsky/odladeni-mcp-updates/main/<soubor>.txt`.
- Logo: `https://plaginy.odladeni.cz/assets/odladeni-mustek.svg`.

## Aktualizace MCP z ChatGPT bez ručního přepisování na serveru
1. Nejdřív spusť přes plugin **Odladění – správa pluginů** nástroje `server_status` a `deploy_diagnostics`. Zapiš `current_sha256`, `backup_sha256`, `staged_sha256` a `recovery_endpoint`.
2. Vytvoř upravený úplný PHP zdroj na základě aktuálního zdroje, ne na základě staré lokální kopie. GitHub `index.php` je referenční kopie, ale před aktualizací ověř, že odpovídá aktivnímu SHA-256; po změně na serveru už může být zastaralá.
3. Připrav soubor `mcp-update-YYYYMMDD.txt` na GitHubu; GitHub plugin může používat `create_file` / `update_file`, pokud je zápis povolen. Nikdy nedávej na veřejný GitHub přístupové klíče, tokeny ani konfiguraci `api/config.php`.
4. Spočítej SHA-256 přesných UTF-8 bajtů nového souboru.
5. Zavolej `deploy_stage_url({url,sha256})` na raw.githubusercontent.com a ověř odpověď `status=staged`, `lint.ok=true` a správný SHA-256. Alternativa pro malé bezpečné změny je `deploy_stage_patch({expected_current_sha256,sha256,replacements})`.
6. Aktivuj `deploy_apply({sha256,expected_current_sha256})`; vyžaduje shodu se skutečně aktivní verzí. Při chybě nezkoušej vynucovat nasazení.
7. Ihned zkontroluj `server_status`, `connection_diagnostics`, `deploy_diagnostics`; kontroluj `current_sha256` a funkčnost.
8. Při chybě použij `deploy_rollback({})` a znovu ověř stav. Recovery endpoint: https://plaginy.odladeni.cz/mcp/recovery.php. Nepředpokládej, že samotný PHP parser nahrazuje provozní test.
9. Ulož výsledky: původní a nový SHA-256, čas, GitHub commit, lint, kontrolu serveru, rollback a trace ID.

## Ověřené výsledky 18. 10. 2026
- Úspěšně otestováno GitHub URL -> stage -> apply -> server_status -> rollback; původní SHA `384498ca43d744f312c33f09bd31c428c2751eec08c814cf893eb48993757e2d`.
- Poté nasazeno vylepšení kontrol po aktualizaci. Produkční SHA-256 MCP: `4cd3efe923e91a0c30b0a372e7be54b1db82f466b60eee39658bea6e1693fe1d`. Server odpovídá `ready`.
- Automatický rollback při simulované chybě *po* aktivaci dosud nebyl end-to-end ověřen; neoznačuj ho za otestovaný.
- Bezpečně ověřeno odmítnutí nepovolené URL, nesprávného SHA-256 a nesprávného očekávaného SHA při aktivaci.
- Administrace `console/index.php` změněna: logo, favicon, horní odkaz Správa pravidel a Google účet s avatarem. Nový SHA-256 `d59e2ce7b3bf5e402187e7770cb3f32a0ddefad971fb6871b90424866841d195`. Záloha přes file_write: `fdded6731ef50188a6f26efc85642612`, původní SHA `2287f14d98faec2dab90a45bd9d98d4d1ca8f2e3a4763738d5b96c788fcca41f`.
- Pro aktualizace **administrace** použij `file_read({file:"console/index.php"})` -> uprav přesný aktuální obsah -> `file_write({file:"console/index.php",content_base64,expected_sha256})` -> `file_read` pro ověření. Při problému `file_restore` s `backup_id` a aktuálním SHA. Tato cesta je jiná než `deploy_stage_url` pro MCP.

## Bezpečnost
- Nepřepisuj bez SHA compare-and-swap.
- Před nasazením vytvoř a ověř zálohu.
- Po nasazení kontroluj reálnou odezvu služby, ne jen syntaxi.
- Neměň ani nemaž pluginy, archivy nebo připojené weby bez konkrétního zadání.
- Neoznačuj všech 42 MCP funkcí za otestované, pokud nebyly jednotlivě ověřeny.
