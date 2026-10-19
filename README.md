# ADC Cookies & Commerce Hub

[![CI](https://github.com/dany547/adc-cookies-commerce-hub-wordpress/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dany547/adc-cookies-commerce-hub-wordpress/actions/workflows/ci.yml?query=branch%3Amain)
[![Release](https://github.com/dany547/adc-cookies-commerce-hub-wordpress/actions/workflows/release.yml/badge.svg)](https://github.com/dany547/adc-cookies-commerce-hub-wordpress/actions/workflows/release.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb3?logo=php&logoColor=white)](#dezvoltare)
[![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-9.5%2B-A4336E?logo=woocommerce&logoColor=white)](https://woocommerce.com)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

Gestionează cookies, consimțământul vizitatorilor și preferințele de marketing. Centralizează integrările de tracking și rapoartele comerciale WooCommerce, cu controale distincte pentru colectarea datelor.

> **Notă GDPR:** plugin-ul oferă *instrumente* tehnice de consimțământ (banner, jurnal, gating pe consimțământ, export/ștergere date). Aceasta **nu** este o certificare GDPR. Răspunderea pentru procesarea datelor rămâne a operatorului site-ului.

## Ce face plugin-ul

- **Cookie CMP**: banner cu patru categorii (necesare, preferințe, statistici, marketing), „Acceptă · Setări · Respinge”, Google Consent Mode v2, Global Privacy Control, jurnal de consimțământ per decizie.
- **Consimțământ marketing (opțional, separat de cookie-uri)**: checkbox voluntar la checkout WooCommerce, preferințe separate e-mail / SMS din pagina „Marketing” a contului clientului, export CSV cu filtru pe perioadă, vizibilitate în comenzi și profile. *Limitare cunoscută:* căsuța de consimțământ la checkout este redată doar de checkout-ul clasic; checkout-ul pe blocuri (Store API) nu o afișează încă, iar comenzile plasate prin bloc nu înregistrează valoare de consimțământ — o actualizare ulterioară va aduce căsuța și în blocuri. Rapoartele de analitice operaționale/comerciale funcționează și pe checkout-ul cu blocuri; validarea lor E2E este separată de această limitare moștenită.
- **Pixeli & evenimente consimțământ-gated**: Meta, TikTok, GA4 (Measurement Protocol) și sGTM se activează fiecare separat și trimit doar cu consimțământ; potrivirea avansată trimite exclusiv hash-uri SHA-256.
- **Analitice operaționale & comerciale**: rapoarte interne (trafic, interacțiuni, reconciliere comercială, retenție, CSV),implicit oprite, rulate local în bazele de date ale site-ului.

## Instalare

1. Descarcă `adc-marketing-X.Y.Z.zip` din [GitHub Releases](../../releases) (nu arhiva auto-generată `source.zip`/`tar.gz` a repository-ului — are alt root).
2. În WordPress: **Pluginuri → Adaugă plugin → Încarcă un plugin**, sau dezarhivează în `wp-content/plugins/` (arhiva conține root-ul `adc-marketing/`).
3. Activează plugin-ul. Necesită WooCommerce 9.5+ și PHP 8.2+.
4. Configurează din meniul **ADC Cookies & Commerce Hub**.

Fizic, plugin-ul rămâne `adc-marketing/adc-marketing.php` cu text domain `adc-marketing`; prefixele de cod (`ADC_*`, `adc_`), opțiunile, tabelele și endpointurile neschimbate din 1.0 — rebranduirea din 2.2.0 afectează doar textele afișate.

## Actualizare

Actualizările vin **exclusiv din acest repository GitHub** (modulul `ADC_Github_Updater`, header `Update URI`), nu din WordPress.org:

- Verificarea versiunii și descărcarea arhivei au loc doar în contexte de administrare (ecranul **Pluginuri** și orice cerere `is_admin()`, inclusiv admin-AJAX), la acțiunile programate (cron) și în CLI (WP-CLI). **Frontendul vizitatorilor nu face nicio cerere externă.** La verificare/descărcare, GitHub vede IP-ul serverului și un User-Agent static (`ADC-Marketing-Updater/...`); nu se trimite altfel de telemetrie, chei sau cookie-uri.
- Fiecare release conține `adc-marketing-X.Y.Z.zip`, `SHA256SUMS` și `update-manifest.json` (câmpuri: `version`, `requires`, `requires_php`, `tested`, `wc_requires`, `package_url`, `sha256`); arhiva este verificată cu SHA-256 înainte de instalare.
- Tagurile de release sunt stabile, de forma `vX.Y.Z` (fără pre-release).

## Versionare (SemVer, o singură sursă)

Sursa autoritativă este `Version:` din antetul `adc-marketing.php`. Celelalte locații se sincronizează și se verifică prin scripturi:

| Acțiune | Comandă |
|---|---|
| Versiunea curentă | `bin/version.sh current` |
| Verifică consistența (antet ↔ `ADC_MARKETING_VERSION` ↔ `Stable tag` ↔ `CHANGELOG.md`) | `bin/version.sh check` |
| Propune versiunea următoare la bump | `bin/version.sh bump patch\|minor\|major` |
| Sincronizează constanta și README.txt din antet (nu rescrie CHANGELOG) | `bin/version.sh sync` |

Bumps automate: workflow-ul **`version-bump.yml`** (dispatch manual `patch`/`minor`/`major`) crează commit-ul + tag-ul `vX.Y.Z` cu permisiunea explicită `contents: write` și apoi *dispatch-uie* `release.yml`. Trigger-ul pe push de tag creat cu `GITHUB_TOKEN` nu ar porni singur (restricție nativă GitHub Actions), de aici dispatch-ul explicit — fără cursă circulară și fără PAT în repository.

Date: `bump` inserează în CHANGELOG `## [X.Y.Z] - în lucru`; responsabilul de release înlocuiește `în lucru` cu data UTC a publicării (`date -u +%F`) în commit-ul/tag-ul de release. `check` compară numărul de versiune, nu data, deci intrările `în lucru` viitoare rămân valide până la publicare.

Confidențialitate, auto-conținut: plugin-ul oferă doar controale tehnice de susținere a conformității (consimțământ, preferințe granulare, gating pe consimțământ, export/ștergere date); **nu** este o certificare juridică și nu face site-ul conform prin el însuși — rămân obligatorii politica de confidențialitate a operatorului, temeiurile juridice, contractele cu procesatori și retenția aplicată. Limitările tehnice active (inclusiv cea de la checkout pe blocuri, mai sus) sunt descrise în acest README; documentația extinsă de arhitectură/conformitate este internă și nu este publicată în repository. Arhiva ZIP de release conține `LICENSE` și `README.txt`. La verificare/descărcare GitHub vede IP-ul serverului și User-Agent-ul static al plugin-ului (fără cookie-uri sau chei).

## Dezvoltare

Cere PHP 8.2+, Node 22+ (doar pentru `node --check`), bash, Python 3 (build/release).

```bash
bash bin/smoke.sh                 # php -l, node --check, bash -n, phpcs (available)
bash bin/analytics-smoke.sh       # + analitice: unit tests izolate (PHP separat per fisier)
php tests/release/version-test.php      # test: sursa unica SemVer + sync
php tests/release/build-test.php        # test: build determinist, allowlist, root, CRC
```

Nu există suita PHPUnit/WP test lib configurată; teste de integrare reale au nevoie de un WordPress de unic folosinta (`bin/analytics-smoke.sh <wp_path>`). E2E nu rulează în CI fără DB real — CI nu raporteaza PASS inventat.

## Release pipeline

```
push / pull_request ──▶ ci.yml            lint + teste (PHP 8.2/8.3/8.4/8.5 × Node suportat)
tag vX.Y.Z ─────────▶ release.yml        valideaza TOATE inainte de orice write, apoi
                                          build determinist + gh release create (GITHUB_TOKEN)
manual dispatch ────▶ version-bump.yml   bin/version.sh bump + commit + tag + dispatch release.yml
```

Build local (fără GitHub):

```bash
bin/build-release.sh --out "$(mktemp -d)"   # dist/ implicit; NU suprascrie, NU sterge
bin/verify-release.sh <dir>/adc-marketing-<ver>.zip
```

`bin/build-release.sh` folosește un **allowlist explicit** (`bin/lib/build_release.py`): runtime PHP (`adc-marketing.php`, `uninstall.php`, `includes/`), `assets/`, `templates/`, `languages/`, plus `LICENSE` si `README.txt`. Exclude `.git*`, `tests/`, `docs/`, `bin/`, `.github/`, `dist/`, `vendor/`, `node_modules/`, `AGENTS.md`, `phpcs.xml.dist` si orice fisier de configurare local. De aceea nu e necesar un `.distignore` separat; allowlist-ul din script este sursa unica.

## Structură

```
adc-marketing.php            # entry fizic neschimbat (Plugin Name afișat = brand nou)
includes/                    # module (autoloader ADC_*), incl. core/class-adc-github-updater.php
assets/ templates/ languages/
bin/                         # smoke, version, build-release, verify-release
tests/                       # analytics (izolate) + release + updater (NU intra in ZIP)
.github/workflows/           # ci.yml, release.yml, version-bump.yml
```

## Licență

GPL v2 sau mai nou. Autor: Dan Mutu.
