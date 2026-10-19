# Analytics operațional — ghid de operare și contract de raportare

Acest document descrie modulul **Analytics operațional** din ADC Marketing: ce
este activ, ce este blocat, ce rapoarte există, cum se exportă și ce trebuie
verificat înainte de a porni ceva. Documentul juridic de lucru este separat:
[`docs/privacy/operational-analytics-review.md`](privacy/operational-analytics-review.md).

Modulul este livrat în două straturi:

- **Raportare financiară din comenzi** — singura parte care poate fi activată
  astăzi. Funcționează **independent de bannerul de consimțământ (CMP)**: nu
  citește, nu scrie și nu depinde de starea consimțământului.
- **Contorizare trafic, UTM și evenimente de interacțiune** — implementate ca
  schemă, clasificatoare și gate-uri, dar **blocate în cod** în această versiune.

## 1. Starea reală a funcțiilor (nu cea „optimistă”)

Contractul de discovery descria gate-uri care se deschid când completai o
referință juridică și o dată de aprobare (`traffic_enabled()` =
`enabled_traffic && basis === 'lia' && reference !== '' && date valid`). În
această versiune **formula nu se aplică**: gate-urile sunt închise direct în
`ADC_Analytics_Settings`, iar sanitizarea forțează `false` la salvare.

| Funcție | Setare stocată | Stare efectivă | Motiv |
|---|---|---|---|
| Raportare comercială | `enabled_commerce` + `commerce_ack` | **poate fi pornită** | operațional, fără date personale |
| Contorizare trafic | `enabled_traffic = false` forțat | **blocată** | lipsește arhitectura pentru dreptul de obiecție Art. 21 GDPR + evaluarea ePrivacy |
| Referință/ dată trafic | se păstrează doar ca notiță | inert | nu deschide nimic, nici cu date completate |
| UTM / campanii | `traffic_utm_enabled = false` forțat | **blocată** | colectarea UTM nu este aprobată |
| `add_to_cart` | `enabled_add_to_cart = false` forțat | **blocată** | aprobare juridică + tehnică separată |
| `begin_checkout` | `enabled_begin_checkout = false` forțat | **indisponibil** | nu există hook server verificat |

Nu există `apply_filters`, constantă sau opțiune care să deschidă aceste
gate-uri. Orice text din interfață care sugerează altceva este o eroare.

**De ce nu se „activează” traficul doar cu o referință LIA:** agregarea fără
identificatori nu poate executa o obiecție individuală (Art. 21), iar un buton
global de oprire nu este echivalentul dreptului de obiecție. Până la decizia
arhitecturală (și la evaluarea ePrivacy privind accesul la terminal), gate-ul
rămâne închis. Nu se colectează UTM, nu se inventează un „opt-out” și nu se
declară conformitate.

**Raportarea comercială nu depinde de consimțământ.** Ea numără comenzi și sume
din WooCommerce, nu persoane. Activarea ei cere doar bifa de confirmare a
definițiilor și a retenției (`commerce_ack`), implicit **oprită**.

## 2. Pornire, configurare, rollback, retenție, dezinstalare

### 2.1 Pornire (commerce)

1. **ADC Marketing → Analytics operațional** (tab `analytics`), secțiunea
   *Rapoarte financiare din comenzi*.
2. Bifați activarea și bifa de confirmare a definițiilor/retenției.
3. Verificați statusurile considerate plătite (implicit `processing`,
   `completed`). Sunt independente de statusurile din modulul de evenimente.
4. Salvați. Dashboardul afișează acum KPI-uri și tabele financiare.

Setările sunt stocate în opțiunea `adc_settings_analytics` și se citesc doar
prin `ADC_Settings::get('analytics', …)`. Tabelul de mai jos listează valorile
și limitele reale (aplicate la salvare și la citire):

| Cheie | Implicit | Limită aplicată |
|---|---|---|
| `enabled_commerce` | `false` | `true/false` |
| `commerce_ack` | `false` | `true/false` |
| `paid_statuses` | `processing`, `completed` | doar aceste două valori |
| `retention_commerce_days` | `730` | `90…3650` |
| `retention_ledger_days` | `365` | `30…1095`, niciodată sub commerce |
| `retention_traffic_days` | `90` | `30…180` |
| `min_cell_count` | `5` | `3…50` |
| `exclude_logged_in_admins` | `true` | `true/false` |
| `bot_filter_enabled` | `true` | `true/false` |

### 2.2 Rollback

- Pentru a opri raportarea: debifați `enabled_commerce` (sau `commerce_ack`) și
  salvați. Nu se mai scrie nimic nou; datele existente rămân până la retenție.
- Pentru a opri complet modulul: dezactivați plugin-ul. Nu se mai execută
  hook-uri, cron și export.
- Nu există rollback „de date” prin cod. Revenirea la o versiune anterioară a
  plugin-ului nu șterge tabelele `adc_analytics_*`; ștergerea se face doar prin
  dezinstalarea cu opțiunea de ștergere activă (vezi 2.4).

### 2.3 Retenție

Un cron zilnic (`adc_analytics_cleanup`, programat la 03:00 în fusul site-ului)
șterge:

- trafic, interacțiuni și (dacă ar exista) campanii mai vechi de
  `retention_traffic_days`;
- agregatele comerciale și de produse mai vechi de `retention_commerce_days`;
- ledger-ul de deduplicare mai vechi de `retention_ledger_days`, care nu poate
  fi mai mic decât retenția comercială (altfel o comandă ar putea fi numărată
  din nou).

Retenția nu este o „copie de siguranță”: după ștergere, seriile vechi nu mai
pot fi reconstruite din agregate, doar din comenzile WooCommerce încă existente.

### 2.4 Dezinstalare și ștergerea explicită a datelor

Ștergerea tabelelor analytics este **opt-in**, prin opțiunea existentă
`consent.delete_data_on_uninstall` (nu există un al doilea întrerupător).
Ordinea corectă:

1. Exportați ce aveți nevoie (secțiunea 3).
2. Activați `consent.delete_data_on_uninstall` **doar dacă** doriți ștergerea
   ireversibilă.
3. Dezinstalați plugin-ul din *Plugins*.

La dezinstalare se șterg tabelele `adc_analytics_traffic`,
`adc_analytics_interactions`, `adc_analytics_commerce_daily`,
`adc_analytics_products_daily`, `adc_analytics_order_ledger`,
`adc_analytics_campaign_daily`, opțiunea `adc_settings_analytics`, versiunea
`adc_analytics_db_version`, tranzientele și cron-ul `adc_analytics_cleanup`.
Fără opțiunea de ștergere, tabelele rămân (comportament intenționat, ca să nu
pierdeți date la o reinstalare).

## 3. Rapoarte și export CSV

### 3.1 Filtre (aceleași în UI, grafice, tabele și CSV)

- interval: presetări 7 / 30 / 90 / 365 zile sau interval custom `from`/`to`;
- monedă: **o singură monedă per raport**, fără conversie;
- tip pagină (`page_type`), canal (`channel`), produs (`product_id`).

Intervalele sunt validate strict (`Y-m-d`, dată reală), inversarea este
corectată, sfârșitul în viitor este limitat la ziua curentă, iar intervalul este
plafonat la **366 de zile**. Datele sunt zile locale ale site-ului.

### 3.2 Rapoarte disponibile

| Cheie raport | Conținut | Identificatori |
|---|---|---|
| `kpis` | comenzi create/plătite, brut, net, TVA, livrare, reduceri, rambursări, AOV, rată rambursări, trafic estimat | niciunul |
| `traffic_series` | cereri de pagină estimate pe zi | niciunul |
| `top_pages` | pagini după vizualizări, cu tip + ID intern | fără titluri, fără URL-uri |
| `channel_distribution` | vizualizări pe canal (direct, căutare, social, referral, intern, necunoscut) | niciunul |
| `commerce_series` | serie zilnică: create, plătite, brut, rambursări, net | niciunul |
| `top_products` | produse după unități, net, TVA, rambursări | doar `product_id` |
| `current_counts` | numărul curent de comenzi pe status, direct din WooCommerce | niciunul |
| `reconciliation` | diferența dintre agregate și comenzi, pe zi (max. 31 zile) | niciunul |

Exportul nu conține niciun identificator individual: fără client, comandă,
e-mail, telefon, IP, URL, user agent. Coloanele sunt exclusiv agregate.

### 3.3 Exportul CSV

- butoanele sunt link-uri `<a>` către
  `admin-post.php?action=adc_analytics_export&report=<cheie>` cu nonce
  `adc_analytics_export_nonce`;
- accesul cere `manage_options`; nonce-ul este verificat înaintea oricărei
  citiri de parametri;
- lista de rapoarte este în allowlist; orice altă cheie primește `400` și este
  audit-logată;
- fișierul are BOM UTF-8, separator `;` și numele
  `analytics-<raport>-<from>-<to>.csv`;
- fiecare celulă este neutralizată împotriva formulelor: `= + - @`, TAB și CR,
  inclusiv când sunt precedate de spații (`" =1+1"`, `"\t=1"`), plus eliminarea
  CR/LF/NUL și plafonare la 500 de caractere;
- fiecare export este înregistrat prin `ADC_Logger` cu raport, interval, monedă
  și ID-ul utilizatorului admin — fără date personale. Refuzurile (capacitate,
  nonce, raport necunoscut) sunt și ele înregistrate.

### 3.4 Suprimarea celulelor mici (aceeași în UI, grafice și CSV)

Pragul este `min_cell_count` (implicit 5). Reguli, aplicate de o singură
funcție (`ADC_Analytics_Reports::suppress_rows()`), deci identice peste tot:

1. O celulă cu valoare `> 0` și `< prag` este ascunsă: valorile numerice devin
   `null` și rândul primește `suppressed = true` (rămâne vizibil ca poziție,
   pentru grafice, cu eticheta „eșantion mic”).
2. **Suprimare complementară:** dacă ar fi ascunsă o singură celulă, se ascunde
   și cea mai mică celulă vizibilă. Astfel totalul publicat nu poate fi folosit
   prin diferență pentru a deduce valoarea ascunsă.
3. Totalul unui breakdown este ascuns (`total_suppressed = true`) dacă este el
   însuși sub prag sau dacă a rămas o singură celulă ascunsă.
4. În KPI-uri nu se ascund sumele magazinului, dar `small_sample` semnalează un
   eșantion sub prag, iar blocul `reference`/note explică asta.

### 3.5 Definiții financiare (explicite, fără atribuire)

```
brut          = SUM(order_total)
net           = SUM(order_total) − SUM(abs(refund_total))
TVA           = SUM(tax_total)
livrare       = SUM(shipping_total) + SUM(shipping_tax)
reduceri      = SUM(discount_total) + SUM(discount_tax)
AOV           = brut / comenzi plătite        (doar dacă > 0)
rată rambursări = rambursări / brut            (doar dacă brut > 0)
```

Semantica este purtată de **numele metricii** (`orders_created`, `orders_paid`,
`order_total`, `refund_total`, `status_reached`), nu de coloana `order_status`,
care este informativă și nu filtrează rapoartele. `status_reached:<status>` este
un fapt separat („comanda a atins statusul”), nu o tranziție și nu o stare
curentă.

Sumele se calculează exclusiv în moneda selectată, la 6 zecimale, fără
conversie și fără împrumut de la altă monedă. Comenzile plătite nu scad la
rambursare: rambursările sunt un fapt separat, afișat separat. Nu există
atribuire (niciun canal nu primește „credit” pentru o comandă), nu există
conversie și nu există abandon de coș.

## 4. Matrice metrică → hook → câmpuri → bază → ePrivacy → retenție → acuratețe → aprobare

| Metrică | Hook / sursă | Câmpuri stocate | Bază propusă | ePrivacy | Retenție | Acuratețe / limitări | Aprobare |
|---|---|---|---|---|---|---|---|
| Cereri de pagină (estimat) | `template_redirect` (gate închis) | `stat_date`, `stat_hour`, `channel`, `page_type`, `object_id`, `views` | interes legitim (LIA) | fără cookie/JS/IP, dar acces la cerere → evaluare necesară | `retention_traffic_days` (30–180) | subestimare din cache/CDN/proxy, boți, prerender | **NEAPROBAT — blocat** |
| Canal (referer) | același hook | doar `channel` (host-ul nu se stochează) | interes legitim (LIA) | idem | idem | „direct” amestecă lipsa referrer-ului | **NEAPROBAT — blocat** |
| Campanii / UTM | niciunul | — (`increment_campaign()` returnează `false`) | — | — | — | nu există colectare | **NU SE COLECTEAZĂ** |
| Comenzi create | `woocommerce_new_order` | `orders_created` (count) | executarea contractului / interes legitim operațional | nu se aplică (fără terminal) | `retention_commerce_days` (90–3650) | include pending/failed/cancelled | cere `commerce_ack` |
| Comenzi plătite | `woocommerce_payment_complete`, `woocommerce_order_status_changed` | `orders_paid` (count) | idem | nu se aplică | idem | o dată per comandă (ledger) | cere `commerce_ack` |
| Venit brut/TVA/livrare/reduceri | procesatorul comercial | `order_total`, `tax_total`, `shipping_total`, `shipping_tax`, `discount_total`, `discount_tax` | idem | nu se aplică | idem | formule proprii, nu paritate WC Analytics | cere `commerce_ack` |
| Rambursări | `woocommerce_order_refunded` | `refund_total`, `refund_units`, `refund_net`, `refund_tax` | idem | nu se aplică | idem | ștergerea/reversarea unei rambursări nu este suportată | cere `commerce_ack` |
| Top produse | procesatorul comercial | `product_id`, `units`, `items_net`, `items_tax`, `refund_*` | idem | nu se aplică | idem | variații se cumulează pe produsul părinte | cere `commerce_ack` |
| `add_to_cart` | `woocommerce_add_to_cart` (gate închis) | `stat_date`, `event_name`, `count` | interes legitim (LIA) | hook server, fără cookie | `retention_traffic_days` | exclude Store API până la verificare | **NEAPROBAT — blocat** |
| `begin_checkout` | niciunul | — | — | — | — | nu există hook server verificat | **INDISPONIBIL** |
| Vizitatori unici | niciunul | — | — | — | — | ar cere identificatori | **INDISPONIBIL prin design** |
| Conversie / atribuire | niciunul | — | — | — | — | ar cere legarea vizită–comandă | **INDISPONIBIL prin design** |
| Abandon de coș | niciunul | — | — | — | — | depinde de `begin_checkout` | **INDISPONIBIL** |

## 5. Limitări care trebuie afișate lângă cifre

- **Cache / CDN / proxy**: o pagină servită din cache nu ajunge la PHP, deci nu
  este numărată. Traficul este estimat și **subestimat**.
- **Boți și prerender**: filtrul este conservator, dar imperfect; pot rămâne
  vizite automate sau pot lipsi vizite reale. `exclude_logged_in_admins` reduce
  zgomotul intern, nu îl elimină.
- **Referrer lipsă**: devine „direct”; o parte din „direct” are alte surse.
- **Eșantion mic**: se aplică regulile din 3.4, inclusiv pentru totaluri.
- **Fără atribuire**: comenzile nu sunt legate de vizite; nu există conversie pe
  canal, ROAS, LTV sau cohorte.
- **Fără vizitatori unici**: nu se stochează cookie-uri, IP-uri, user agent sau
  identificatori; nu există deci „unique visitors”.
- **Fără conversie dintr-o singură sursă de adevăr**: cifrele financiare sunt
  proprii, cu formule explicite; `reconciliation()` arată diferențele, nu le
  „repair-uiește”.
- **Fus orar**: ziua este cea a site-ului; o comandă plătită aproape de miezul
  nopții poate cădea în ziua vecină față de raportarea în UTC.
- **Monedă**: sumele nu se convertesc; rapoartele pe altă monedă se cer separat.

## 6. Ce NU face modulul

- nu setează cookie-uri, nu scrie în `localStorage`, nu emite beacon JS;
- nu citește sau scrie consimțământul, nu depinde de CMP și nu îl influențează;
- nu face cereri HTTP către servicii externe și nu trimite date în afara
  WordPress;
- nu stochează IP, user agent, URL complet, query string, e-mail, telefon, nume,
  ID de client sau ID de comandă în agregate (ledger-ul păstrează `order_id`
  doar pentru deduplicare, nu este raportat și nu este exportabil);
- nu are endpoint REST/AJAX public și nu expune statistici neadministratorilor;
- nu conține SQL în afara `ADC_Analytics_Store` / `ADC_Analytics_Schema`;
- nu conține biblioteci terțe, CDN sau fonturi externe.

## 7. Contract pentru dezvoltatori (formele array)

Semnăturile publice din discovery sunt păstrate; singurele adăugiri sunt
argumente opționale de filtrare, fără schimbare de semnătură:

- `ADC_Analytics_Reports::kpis(array $range, string $currency, array $filters = [])`
- `ADC_Analytics_Reports::top_products(array $range, string $currency, int $limit = 20, array $filters = [])`
- `ADC_Analytics_Reports::channel_distribution(array $range, array $filters = [])`

Chei stabile, folosite identic de dashboard, grafice și CSV:

```
range()                => preset, from, to, days, prev_from, prev_to,
                          prev_complete, valid, errors[], clamped, max_days, timezone
kpis()                 => from, to, days, currency, currency_valid, paid_statuses[],
                          orders_created, orders_paid, gross_revenue, net_revenue,
                          tax, shipping, discounts, refunds, aov, refund_rate,
                          traffic_views, traffic_estimated, gates{commerce,traffic},
                          traffic_available, traffic_reason, min_cell, small_sample,
                          filters{page_type,object_id,product_id,channel,valid,ignored},
                          notes[], comparison{available,reason,previous,delta,evidence}
traffic_series()       => available, reason, from, to, filters{...}, granularity,
                          rows[{date,views,suppressed}], views_total, suppressed_count,
                          complementary, total_suppressed, min_cell, truncated, row_count, limit
top_pages()            => ... + rows[{page_type,object_id,label,views,suppressed}], distinct_pages
channel_distribution() => ... + rows[{channel,label,views,share,suppressed}]
commerce_series()      => ... + currency, currency_valid, paid_statuses[],
                          rows[{date,orders_created,orders_paid,gross_revenue,refunds,net_revenue,suppressed}],
                          totals{orders_created,orders_paid,gross_revenue,refunds,net_revenue}|null
top_products()         => ... + rows[{product_id,label,units,net,tax,refund_units,refund_net,suppressed}],
                          totals{units,net,tax,refund_units,refund_net}|null, distinct_products
current_order_counts() => available, reason, counts{status:int}, total, statuses[], source, as_of
reconciliation()       => available, reason, currency, days[{date,diff,warn}], max_diff, warn, max_days
available_currencies()  => available, reason, base, currencies[], partial, source
warnings()             => [ {code, severity, text} ]
suppress_rows()        => rows, suppressed_count, complementary, total, total_suppressed, min_cell
```

Valorile `null` sunt intenționate: înseamnă „indisponibil” sau „ascuns din
cauza eșantionului mic”, niciodată zero.

`ADC_Analytics_Csv` expune `NONCE`, `ACTION`, `allowed_reports()`,
`build($report, $args)` (fără output), `stream($report, $args)` (scrie CSV),
`cell($value)` (protecția anti-formulă) și `export_url($report, $args)`.

## 8. Verificare locală

```bash
php -l includes/analytics/class-adc-analytics-reports.php
php -l includes/analytics/class-adc-analytics-csv.php
php tests/analytics/reports-test.php
php tests/analytics/csv-test.php
bash -n bin/analytics-smoke.sh
bin/analytics-smoke.sh            # php -l + teste unitare + bin/smoke.sh
bin/analytics-smoke.sh /path/wp   # + integrare, DOAR pe un WordPress disposable
```

Integrarea se sare fără un runtime WordPress; nu se instalează dependențe, nu se
face deploy și nu se rulează pe producție.
