# ncore

nCore new releases notifications – figyeli az nCore RSS-t, és e-mailt küld, ha a listádon lévő tételből új release jelenik meg (cím, nyelv, minőség, új epizód).

## Fájlok

| Fájl | Mire való |
|---|---|
| `index.php` | Webes felület a lista szerkesztéséhez (csurgay.com/ncore), jelszóval |
| `check.php` | Óránkénti ellenőrzés, cronból (webről nem futtatható) |
| `lib.php` | RSS, release-cím elemzés, párosítás, e-mail |
| `schema.sql` | MySQL táblák |
| `config.example.php` | Minta a `config.php`-hoz (az nincs a gitben) |
| `.htaccess` | Csak az `index.php` érhető el a webről |

## Telepítés

1. A repó tartalmát másold a `public_html/ncore/` mappába.
2. phpMyAdminban a `csurgay_ncore` adatbázison futtasd le a `schema.sql`-t.
3. Másold a `config.example.php`-t `config.php` néven, és töltsd ki:
   - DB-jelszó
   - RSS link(ek): nCore → Profil → RSS. Kategóriánként külön is felveheted (pl. sorozat HUN SD/HD, film HUN HD).
   - e-mail cím (`to`), feladó (`from`: lehetőleg `@csurgay.com`-os)
   - `ui_password_hash`: `php -r 'echo password_hash("jelszavad", PASSWORD_DEFAULT), PHP_EOL;'`
4. cPanel → Cron Jobs, óránként (pl. minden óra 7. percében):
   ```
   7 * * * * /usr/local/bin/php /home/<cpanel-user>/public_html/ncore/check.php >/dev/null 2>&1
   ```
   (A PHP elérési útja tárhelyenként eltérhet: cPanelben látszik.)
5. Kipróbálás: `php check.php --dry-run`, vagy a weboldalon az **Előnézet most** gomb. Ilyenkor nem megy ki levél.

## Hogyan párosít

- **Kulcsszavak:** mind szerepeljen a címben, teljes szóként. A kis- és nagybetű, valamint az ékezet nem számít. A `-szo` kizár (pl. `the office -us`). Ha üresen hagyod, a név szavait használja.
- **Nyelv:** `HUN`, ha a címben vagy a kategóriában szerepel a HUN; különben `ENG`.
- **Minőség:** `2160p` / `1080p` / `720p` a címből; ha egyik sem szerepel, `SD`.
- **Sorozat:** `S02E05`, `2x05` vagy évadpakk (`S02`). Csak az évadnál vagy az utolsó látott epizódnál újabbra jelez, és értesítés után a számot magától lépteti.
- **Film:** sorozat-epizódra nem jelez. Az „első találat után kikapcsol” opcióval a figyelés egyszeri.
- Egy torrentről tételenként csak egyszer jön levél. Ha a levelezés hibát ad, a következő futás újrapróbálja.
- A levélben a torrent **adatlapjának** linkje szerepel, a passkey-es letöltő link nem.

Korlát: az RSS csak a legfrissebb elemeket mutatja, ezért a figyelő az új megjelenéseket fogja meg, a régebben feltöltötteket nem.
