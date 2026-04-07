# Smart Admin Plugin Manager — Analýza a plán vylepšení

> Verze pluginu: **1.3.6** · WP ≥ 6.0 · PHP ≥ 7.4 · License GPLv2+
> Cíl dokumentu: posunout plugin na TOP úroveň mezi WordPress plugin managery, bez SaaS a placených závislostí.
> Datum analýzy: 2026-04-07

---

## 1. Shrnutí (TL;DR)

Smart Admin Plugin Manager (SAPM) je technicky velmi vyspělý plugin pro selektivní načítání pluginů v adminu i na frontendu. Architektura je OOP, kód dodržuje WordPress Coding Standards, bezpečnostní profil je **nízce rizikový**. Hlavní mezery jsou v **operačních funkcích** (import/export, šablony pravidel), **testování** (chybí automatizované testy), **nedokončeném frontend asset manageru** a v několika drobných **hardeningových detailech**. Plugin nepoužívá žádné Composer / SaaS / placené závislosti a tento stav je třeba zachovat.

**Verdikt**: ~80 % cesty k TOP řešení. Níže uvedený plán (fáze 1–5) pokrývá zbývajících 20 %.

---

## 2. Architektura pluginu

### 2.1 Struktura souborů

| Soubor | Řádky | Role |
|---|---:|---|
| `smart-admin-plugin-manager.php` | ~167 | Entry point, aktivace/deaktivace, MU-loader, textdomain |
| `includes/class-sapm-core.php` | ~2000+ | Filtrování pluginů (`option_active_plugins` prio 1), pravidla, sampling |
| `includes/class-sapm-database.php` | ~1400+ | Custom tabulka `{prefix}_sapm_sampling_data`, auto-návrhy, cleanup |
| `includes/class-sapm-admin.php` | ~3200+ | Admin UI, 22 AJAX handlerů, drawer, menu snapshoty |
| `includes/class-sapm-frontend.php` | ~2400+ | Frontend optimizer, kontext (WC, archivy…), asset dequeue |
| `includes/class-sapm-dependencies.php` | ~400+ | Cascade blocking pro závislé pluginy (WC ekosystém) |
| `includes/class-sapm-update-optimizer.php` | ~700+ | Filtrování update check, TTL prodloužení |
| `includes/class-sapm-github-updater.php` | ~600+ | Self-update z GitHubu, SHA-256 ověření balíčku |
| `templates/mu-loader.php` | ~30 | Šablona MU-pluginu |
| `uninstall.php` | ~299 | Kompletní úklid (multisite-aware) |

**Celkem ~12 200 řádků PHP.**

### 2.2 Klíčové mechanismy
- `option_active_plugins` filtr s prioritou 1 (dřív než ostatní).
- MU-plugin loader pro velmi časnou inicializaci.
- 3 stavy pravidel (enabled / disabled / deferred) + hierarchie global → group → screen.
- Sampling performance: 100 % admin, 10 % AJAX/REST/Cron/CLI, 5 % frontend.
- Auto mode s 70% confidence threshold.
- GitHub updater: pouze čtení metadat, SHA-256 whitelist host.

---

## 3. Bezpečnostní audit

### 3.1 Přehled

| Oblast | Stav | Poznámka |
|---|---|---|
| Nonces | ✅ OK | Všech 22 AJAX endpointů má `check_ajax_referer()` |
| Capability checks | ✅ OK | `manage_options` na všech zápisech + render |
| Sanitizace vstupů | ✅ OK | `sanitize_key/text_field/file_name/title` |
| Escape výstupů | ✅ OK | `esc_html/attr/url`, `wp_json_encode` |
| SQL Injection | ✅ OK | `$wpdb->prepare()` všude, žádná konkatenace |
| File operace | ✅ OK | Jen lokální, validace cest přes `wp_normalize_path()` |
| `eval/exec/unserialize` | ✅ Žádné výskyty |
| XSS | ✅ OK | Lokalizace přes `wp_localize_script` |
| CSRF | ✅ OK | Nonces + referer check |
| Composer / SaaS / telemetrie | ✅ Žádné |
| GitHub updater | ✅ OK | SHA-256 verifikace + whitelist hostu |

### 3.2 Drobné nálezy a doporučení (hardening)

1. **`$_SERVER['REQUEST_URI']` / `HTTP_HOST`** — používáno v `class-sapm-core.php:430,560,709,1312,1632,1648` a `class-sapm-admin.php:2186,2191,3111-3112`. Čtení pro kontext, ne pro autorizaci. **Doporučení**: zavést helper `sapm_get_request_uri()` s `wp_unslash() + esc_url_raw() + parse_url()` a volat ho konzistentně.
2. **`$GLOBALS['pagenow']`** — bezpečné, ale doporučit whitelist hodnot při použití jako klíče pravidel (obrana do hloubky).
3. **Nonce lifetime pro drawer** — zvážit kratší lifetime (např. `nonce_life` filtr) pro `sapm_drawer_nonce`, protože umožňuje rychlé toggly pravidel.
4. **GitHub updater — Signature**: aktuálně SHA-256 hash bere z GitHub release metadat → hash i balíček přicházejí ze stejného zdroje. **Doporučení**: přidat volitelnou **detached GPG/minisign signaturu** release (veřejný klíč přibalit v pluginu), čímž se zvýší odolnost proti kompromitaci GH účtu.
5. **MU-loader zápis** (`smart-admin-plugin-manager.php:121-135`) — již ověřuje cestu, ale doplnit: kontrolu `is_writable()`, atomický zápis přes `.tmp` + `rename()`, a `chmod 0644`.
6. **Uninstall cesta** — ověřit `defined('WP_UNINSTALL_PLUGIN')` a `current_user_can('delete_plugins')` jako první řádky (pokud už tam není).
7. **AJAX endpointy vracející data** (`get_sampling_stats`, `get_frontend_asset_audit`) — zvážit rate-limit (transient counter per user), aby nešly zneužít k DoS administrace.
8. **Chybí `Content-Security-Policy` hints** pro admin stránku pluginu — volitelné, přidat `X-Frame-Options: DENY` na vlastní stránku nastavení.
9. **Nonce nejsou v URL query** ✅ — dobré. Ujistit se, že žádný GET link neobsahuje sensitivní akce bez `wp_nonce_url()`.
10. **Logování** — zvážit `sapm_security_event` hook pro logování odepřených nonce/cap, aby administrátor viděl pokusy o zneužití.

### 3.3 Rizikový profil: **LOW**
Žádné nalezené kritické ani vysoké chyby. Doporučení výše jsou typu „defense in depth".

---

## 4. Kvalita kódu

| Oblast | Hodnocení | Komentář |
|---|---|---|
| OOP struktura | ★★★★★ | 6 singleton tříd, jasné odpovědnosti |
| WPCS | ★★★★☆ | Dodržuje, chybí formální CI lint |
| i18n | ★★★★☆ | ~77 řetězců, textdomain `sapm`, chybí `.pot` |
| Testy | ★☆☆☆☆ | Žádné PHPUnit ani integration testy |
| Dokumentace | ★★★☆☆ | Dobrý README, chybí inline PHPDoc u složitých algoritmů |
| Caching | ★★★★★ | Transients + per-request cache |
| Duplikace | ★★★★☆ | Minimální |
| Autoloading | ★★★☆☆ | Manuální `require_once` (pro velikost OK) |

---

## 5. Výkon

- Žádné N+1 dotazy ani dotazy v cyklech.
- Sampling má zanedbatelný overhead (~0.1–0.2 ms průměr).
- Custom tabulka + 30denní cron cleanup.
- Update Optimizer efektivně škrtí `api.wordpress.org`.

**Doporučení**: přidat index na `(plugin_file, screen_id, ts)` pokud už není; přidat volitelné `Object Cache` wrappery pro rule loader.

---

## 6. Feature-gap vs. konkurence

| Funkce | SAPM | Gap |
|---|---|---|
| Import/export pravidel | ❌ | **HIGH** |
| Šablony pravidel (WC-only, Blog-only…) | ❌ | **HIGH** |
| REST API pro pravidla (WP-CLI + automatizace) | ❌ | **HIGH** |
| Per-capability pravidla (nejen `manage_options`) | ❌ | MED |
| Multisite dedikované UI | ❌ | MED |
| Naplánované změny pravidel (maintenance window) | ❌ | MED |
| Kompletní Asset Audit UI | 🟡 částečné | **HIGH** (README to zmiňuje jako nedokončené) |
| Bulk operations / undo | ❌ | MED |
| Diagnostika konfliktů (co kdybych zablokoval X?) | ❌ | MED |
| Export diagnostického reportu (.zip) pro support | ❌ | LOW |

---

## 7. Přístupnost, UX, i18n

- ARIA labely přítomné, ale drawer postrádá kompletní landmark strukturu.
- Stavy jsou rozlišeny barvou (gray/green/red/orange) — **přidat text/ikonu** pro a11y.
- Chybí klávesové zkratky v draweru.
- Vizuální feedback při uložení pravidla je OK (toast).
- Chybí onboarding/wizard pro nové uživatele.
- Chybí „What if" režim (simulace dopadu pravidla bez aplikace).

---

## 8. Návrh vylepšení — Implementační plán

### Fáze 1 — Bezpečnost & stabilita (1–2 týdny)
**Cíl**: Hardening bez funkčních změn.

1. Helper `SAPM_Security::get_server_var($key)` s `wp_unslash()` + sanitizace, nahradit všechny přímé `$_SERVER`.
2. Whitelist validace pro `$pagenow` při použití jako klíč pravidla.
3. GitHub updater: volitelná **minisign/GPG detached signature** (veřejný klíč přibalen v `/security/sapm-release.pub`), fallback na SHA-256 pokud signatura chybí.
4. Atomický zápis MU-loaderu (`*.tmp` → `rename()` + `chmod 0644`).
5. Přidat `sapm_security_event` akci pro logování odepřených požadavků.
6. Rate-limiting AJAX endpointů přes transient counter (`sapm_rl_{user_id}_{action}`).
7. Přidat `SECURITY.md` s postupem hlášení zranitelností (GitHub private advisory).

**Výstup**: verze 1.4.0, zero breaking changes.

### Fáze 2 — Testování & CI (2–3 týdny)
**Cíl**: Kvalita, aby další změny byly bezpečné.

1. PHPUnit + **WP-Brain Monkey** nebo `wp-phpunit` fixture.
2. Unit testy pro: rule matcher, dependency cascade, request-type detector, performance sampler, sanitizery.
3. Integrační testy přes `wp-env` (Docker) — aktivace, AJAX round-trip, uninstall.
4. GitHub Actions: `phpcs` (WPCS), `phpstan` level 6, `phpunit`, `psalm` (volitelné).
5. Přidat `.phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`.
6. Generovat `languages/sapm.pot` přes `wp i18n make-pot` v CI.
7. Badge do README: CI status, coverage.

**Výstup**: stabilní základna pro další fáze.

### Fáze 3 — Operační funkce (2–3 týdny)
**Cíl**: Zavřít hlavní feature-gap.

1. **Import / Export pravidel** — JSON soubor, verzovaný schéma, hash integrity.
2. **Rule Templates Library** — in-plugin katalog (woocommerce-only, blog-only, headless, builder-mode…), lokálně v `/templates/rules/*.json`, **bez** vzdáleného stahování.
3. **WP-CLI příkazy**: `wp sapm rules list/add/remove/export/import`, `wp sapm mode auto|manual`, `wp sapm sampling purge`.
4. **REST API** (namespace `sapm/v1`) chráněný `manage_options` + aplikační hesla:
   - `GET/POST /rules`, `GET /sampling/stats`, `POST /mode`, `GET /suggestions`.
5. **Bulk operations + Undo stack** (last 10 změn, uloženo v `options` s TTL).
6. **Rule preview / „What if"** režim — vypočítá dopad bez aktivace.
7. **Support Bundle Export** — zabalí pravidla, sampling shrnutí, site-health do `.zip` (bez osobních dat).

**Výstup**: verze 1.5.0.

### Fáze 4 — Frontend & Asset Manager (2–3 týdny)
**Cíl**: Dokončit část, kterou README označuje jako nedokončenou.

1. Dokončit **Asset Audit** UI — live scan enqueued CSS/JS per kontext.
2. Per-context asset dequeue s „allow all except X" a „deny all except X".
3. **Per-post/per-term override** UI (už je backend, chybí UX).
4. **Critical CSS detekce** (pouze heuristika lokálně, žádné SaaS).
5. Integrace s WP `wp_enqueue_scripts` auditorem, report do drawer.

**Výstup**: verze 1.6.0.

### Fáze 5 — UX, A11y, Dokumentace (1–2 týdny)
1. Onboarding wizard (3 kroky): detekce profilu → doporučená šablona → zapnutí auto módu.
2. Plná ARIA pro drawer (`role="complementary"`, focus trap v panelu).
3. Textové labely vedle barev stavů.
4. Klávesové zkratky (Shift+P, Shift+D).
5. Přepracovaný README + `/docs/` složka (architektura, security model, hooks reference).
6. Inline PHPDoc pro složité algoritmy (sampler, suggestion engine, cascade).
7. Video/GIF ukázky v README (statické, v repu).

**Výstup**: verze 2.0.0 — TOP řešení.

---

## 9. Roadmapa verzí

| Verze | Fáze | Hlavní obsah |
|---|---|---|
| 1.4.0 | 1 | Security hardening, signatury, rate-limit |
| 1.4.x | 2 | CI, testy, PHPCS/PHPStan, POT |
| 1.5.0 | 3 | Import/export, templates, WP-CLI, REST, undo |
| 1.6.0 | 4 | Asset manager, critical CSS heuristika |
| 2.0.0 | 5 | UX/A11y/Docs — stable TOP release |

---

## 10. Principy, které zůstávají neporušené

- ❌ Žádné SaaS, žádná telemetrie, žádné „phone home".
- ❌ Žádné placené závislosti ani freemium pasti.
- ❌ Žádné Composer runtime závislosti (dev-only jsou OK: PHPUnit, PHPCS, PHPStan).
- ✅ Vše lokální, GPLv2+.
- ✅ Uninstall smaže všechna data.
- ✅ Bezpečnost > funkce.
- ✅ Backward compatibility v rámci major verze.

---

## 11. Akceptační kritéria TOP statusu

- [ ] 0 HIGH/CRITICAL nálezů z PHPStan level 6 + Psalm.
- [ ] >70 % line coverage unit testů, kritické cesty 100 %.
- [ ] Zero breaking change mezi 1.x verzemi.
- [ ] Kompletní WP-CLI + REST API.
- [ ] Import/Export + min. 6 šablon pravidel.
- [ ] Dokončený frontend asset manager.
- [ ] A11y audit (axe-core) bez blokujících nálezů.
- [ ] `.pot` soubor generován v CI, min. 1 úplný překlad (cs_CZ).
- [ ] `SECURITY.md` + koordinovaný disclosure proces.
- [ ] Signed releases (minisign).

---

*Konec analýzy. Dokument neobsahuje žádné změny kódu — pouze doporučení.*
