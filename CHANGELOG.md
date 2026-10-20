# Changelog

Toate modificările notabile ale acestui proiect sunt documentate aici. Formatul urmează
[Keep a Changelog](https://keepachangelog.com/ro/1.1.0/) și proiectul respectă
[SemVer](https://semver.org/lang/ro/).

Sursa unică autoritativă pentru versiune este antetul `Version:` din
`adc-marketing.php`. Constanta `ADC_MARKETING_VERSION`, `Stable tag:` din
`README.txt` și primul entry din acest fișier se sincronizează prin
`bin/version.sh` (vezi README.md → *Versioning*).

Note despre date: `bin/version.sh bump` inserează intrarea nouă ca
`## [X.Y.Z] - în lucru`; `check` compară doar numărul de versiune, nu data.
Înlocuiește `în lucru` cu data UTC a publicării (`date -u +%F`) în același
commit/tag de release — workflow-ul `version-bump.yml` nu rescrie dată; o face
responsabilul de release înainte de tag. Intrările viitoare `în lucru` rămân
neatinse până la publicare.

## [2.2.4] - 2026-10-20

### Corectat
- Fatal la autentificare: `ADC_Consent::hydrate_on_login()` primea `user_login` (string) în loc de ID.
- Audit al semnăturilor hook-urilor (tracking, analytics, coș WooCommerce) și test de runtime `tests/hook-signatures-test.php` legat în `bin/smoke.sh`.

## [2.2.3] - 2026-10-20

### Adăugat
- Autoloader: suport pentru trait-uri (trait-adc-*.php).

## [2.2.2] - 2026-10-20

### Adăugat
- Contor statistic de trafic anonim și agregat, activabil explicit (Sec-GPC/DNT respectate).
- Componentul info-icon cu modal pentru explicații ale opțiunilor.
- Traduceri en_US și en_GB.

## [2.2.1] - 2026-10-20

### Adăugat
- Pagină Dashboard dedicată pentru statisticile analitice; tab-ul Analytics păstrează doar setările.
- Navigație în două niveluri (Dashboard, Consimțământ, Măsurare, Integrări, Jurnale & Debug), antet cu logo și iconiță de meniu din adc-security.

### Modificat
- Shell de administrare fluid și responsive, butoane unificate, bară Salvează lipicioasă.

## [2.2.0] - 2026-10-19

### Modificat
- **Rebrandire afișată**: numele plugin-ului devine „ADC Cookies & Commerce Hub”
  (antet `Plugin Name`, titlul și meniul din administrare, aria-labels, documentație
  pentru utilizator). Slug-ul paginii `adc-marketing`, text domain `adc-marketing`,
  prefixele `ADC_`/`adc_`, opțiunile, tabelele, endpointurile REST și fișierul principal
  `adc-marketing/adc-marketing.php` rămân neschimbate — fără break de API public.
- `Plugin URI` și `Update URI` indică repository-ul
  `dany547/adc-cookies-commerce-hub-wordpress` (header-ul `Update URI` previne
  coliziunea de slug cu WordPress.org).

### Adăugat
- **Actualizări self-hosted din GitHub Releases** (modul `ADC_Github_Updater`,
  registrul de module din `ADC_Marketing::boot_modules()`): verificare a versiunii
  și instalare din arhiva oficială `adc-marketing-X.Y.Z.zip`, cu manifest
  `update-manifest.json` și sum de control SHA-256. Cererile externe către GitHub
  au loc doar în ecranul „Pluginuri” din administrare și la cron — niciodată pe
  frontendul vizitatorilor; nu se trimite telemetrie.
- **Pipeline de release**: CI (PHP 8.2–8.5 + Node, lint, teste izolate),
  versionare cu o singură sursă autoritativă (`bin/version.sh check|sync|bump`), build
  determinist cu allowlist (`bin/build-release.sh`) și validare (`bin/verify-release.sh`),
  workflow-uri GitHub Actions: `ci.yml`, `release.yml` (trigger pe tag `vX.Y.Z`,
  cu validare înainte de publicare) și `version-bump.yml` (dispatch manual
  patch/minor/major).

### Neschimbat (intenționat)
- `ADC_MARKETING_DB_VERSION` rămâne `1` — această versiune nu modifică schema de bază.

## [2.1.0] - 2026-10-19

### Modificat
- Ordinea implicită a butoanelor bannerului: Acceptă · Setări · Respinge (configurabilă din tabul „Banner consimțământ”).
- Spațiu configurabil între „Acceptă” și celelalte butoane (0–48 px, implicit 16), limitat la 12% din rând.
- Verificare de conformitate a culorilor (proeminența butonului „Refuză”) cu avertisment la salvare.
- Presetul „Culoare brand” nu mai colorează butonul „Refuză”; câmpul „Culoare brand (accent)” se salvează corect la instalări noi.

## [2.0.0] - 2026-10-18

### Adăugat
- Banner de cookie-uri cu Google Consent Mode v2, presetări, layout-uri și culori configurabile.
- dataLayer pentru evenimente de e-commerce, GTM și trimitere server-side (sGTM).
- Integrări opționale: Meta, TikTok, GA4 (Measurement Protocol), sGTM — fiecare gated pe consimțământ.
- Potrivire avansată cu date hash-uite (SHA-256); jurnale și diagnostice în administrare.
- Pagină unică de administrare cu taburi sub meniul plugin-ului.

### Modificat
- Ștergerea de date personale elimină și IP-urile vechi (1.0); dezinstalarea opțională curăță tabelele, opțiunile și programările.
- Consimțământul de la checkout salvează hash SHA-256 al textului afișat și hash cu cheie al IP-ului (IP-ul brut nu se mai salvează).
- Export CSV: neutralizare formule și a prefixelor `+`/`-`; linkuri cu nonce, interval de date validat.

## [1.0.0]

### Adăugat
- Versiunea inițială: checkbox de consimțământ la checkout, preferințe de marketing în contul clientului, mesaj personalizabil, export CSV, vizibilitate în administrare, filtru comenzi.
