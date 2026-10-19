# Analytics operațional — dosar de evaluare juridică și privacy (draft de lucru)

> **Stare: DRAFT — neaprobat.** Acest document nu declară conformitate, nu
> substituie o evaluare juridică și nu autorizează colectarea de date. Este
> materialul de lucru pentru operator și DPO. Până la semnarea secțiunii 8,
> funcțiile marcate „blocat” rămân închise în cod
> (`ADC_Analytics_Settings` returnează `false` pentru trafic, UTM,
> `add_to_cart` și `begin_checkout`).

Documentul de operare este [`docs/OPERATIONAL_ANALYTICS.md`](../OPERATIONAL_ANALYTICS.md).

---

## 1. Obiectul evaluării

Modul **Analytics operațional** din plugin-ul ADC Marketing (WordPress +
WooCommerce), care produce:

1. **Agregate financiare din comenzi** (`adc_analytics_commerce_daily`,
   `adc_analytics_products_daily`) și un ledger de deduplicare
   (`adc_analytics_order_ledger`) — singura parte activabilă astăzi.
2. **Agregate de trafic fără identificatori** (`adc_analytics_traffic`), un
   tabel de interacțiuni (`adc_analytics_interactions`) și un tabel de campanii
   (`adc_analytics_campaign_daily`) — implementate, dar **blocate în cod**.

Nu fac obiectul acestei evaluări: bannerul de consimțământ, pipeline-ul de
evenimente/pixeli, exportul de consimțământ, autentificarea WordPress sau
procesatorul financiar ulterior.

## 2. Ce nu se colectează (fapte verificabile în cod)

- fără cookie-uri, `localStorage`, `sessionStorage` sau amprente de browser;
- fără adrese IP, user agent, URL complet, query string, titlu de pagină;
- fără ID de utilizator, ID de sesiune sau ID de comandă în agregate;
- fără e-mail, telefon, nume, adresă;
- fără parametri UTM, `gclid`/`fbclid` (funcția de scriere în campanii
  returnează `false`);
- fără cereri HTTP către terți, fără CDN de scripturi, fără beacon JS;
- fără endpoint REST/AJAX public și fără expunere către utilizatori neautentificați
  ca administratori.

Ledger-ul păstrează `order_id` exclusiv pentru idempotență (o comandă este
numărată o singură dată). Nu este interogat de rapoarte, nu apare în CSV și nu
este agregat pe persoană.

## 3. Temeiuri și evaluări propuse

| Prelucrare | Temei propus | Necesitate / proporționalitate | Observații |
|---|---|---|---|
| Agregate financiare din comenzi | executarea contractului (Art. 6(1)(b)) pentru evidența comenzilor; interes legitim (Art. 6(1)(f)) pentru raportarea operațională agregată | cifrele sunt sume și numărători, nu profiluri; nu se leagă de persoane | nu depinde de consimțământ și nu poate fi „retrasă” prin CMP |
| Contorizare trafic agregată | interes legitim (Art. 6(1)(f)) — **LIA necesară** | fără identificatori, retenție scurtă, prag de celulă mică | **neaprobat**; necesită și evaluarea ePrivacy (acces la terminal) |
| Canal din referer | interes legitim (Art. 6(1)(f)) — LIA | se stochează doar categoria, nu host-ul | idem |
| `add_to_cart` | interes legitim (Art. 6(1)(f)) — LIA separată | eveniment server-side, fără cookie | **neaprobat** |
| `begin_checkout` | — | — | nu există hook server verificat; rămâne indisponibil |
| UTM / campanii | — | — | **nu se colectează** |

### 3.1 ePrivacy

Traficul ar fi numărat pe server, fără scriere în terminal și fără citire din
terminal, deci nu intră în categoria cookie-urilor. Totuși, prelucrarea unei
cereri HTTP este o prelucrare de date personale atunci când există orice
element identificator (inclusiv IP în log-uri), iar evaluarea trebuie să acopere:
log-urile serverului, cache-ul/CDN-ul, IP-urile reținute de găzduire și
eventuala corelare cu alte surse. Modulul nu stochează aceste elemente; rămâne
de verificat infrastructura.

### 3.2 Art. 21 GDPR — dreptul la obiecție (blocajul real)

Agregarea fără identificatori face imposibilă executarea unei obiecții
individuale: nu există o înregistrare pe care să o oprești pentru o persoană.
Un buton global „nu număra traficul” nu este echivalent cu Art. 21 și nu poate
fi prezentat ca atare. Din acest motiv:

- gate-urile de trafic, UTM, `add_to_cart` și `begin_checkout` sunt **închise în
  cod**, indiferent de referința LIA sau de data de aprobare completată în UI;
- referința și data se păstrează doar ca notițe pentru analiza viitoare și nu
  deschid nimic (formula „optimistă” din discovery — `enabled_traffic && lia &&
  reference && date` — **nu este implementată**);
- orice arhitectură viitoare trebuie să rezolve explicit obiecția (de exemplu,
  un mecanism verificabil la nivel de vizitator sau renunțarea la prelucrare) și
  să fie aprobată separat.

## 4. LIA — draft (Legitimate Interests Assessment)

Se completează de operator cu DPO; nu se publică înainte de semnare.

**4.1 Operator:** `[OPERATOR]`, CUI `[CUI]`, adresă `[ADRESA]`, contact
`[CONTACT]`, DPO `[DPO]`.

**4.2 Interesul legitim:** înțelegerea agregată a funcționării magazinului
(pagini cerute, canale de descoperire, volum și valoare a comenzilor) pentru
operare, securitate și dezvoltare de produs, fără urmărirea persoanelor.

**4.3 Necesitatea:** scopul nu poate fi atins cu date de consimțământ (ar
introduce un temei inadecvat pentru o simplă numărare) și nici cu instrumente
care creează profiluri. Sunt suficiente numărători zilnice, fără identificatori.

**4.4 Test de echilibrare:**

- *Beneficii:* operare și îmbunătățire, detectarea problemelor.
- *Impact:* minim pentru trafic (fără identificatori, fără profilare, fără
  decizii automate), moderat pentru comenzi (date de contract deja existente în
  WooCommerce).
- *Așteptări rezonabile:* un cumpărător se așteaptă la evidență financiară; un
  vizitator nu se așteaptă la numărare agregată pe server.
- *Garantii:* retenție limitată, prag de celulă mică, export fără identificatori,
  acces doar `manage_options`, audit de export, fără transfer extern.

**4.5 Concluzie propusă:** interes legitim pentru agregatele financiare
(raportare operațională) și, **condiționat de rezolvarea Art. 21 și a evaluării
ePrivacy**, pentru trafic. Până la semnare: trafic **oprit**.

**4.6 Referință LIA:** `[ID / URL / data documentului]`
**4.7 Data evaluării:** `[ZZ.LL.AAAA]`
**4.8 Revizuire:** `[la 12 luni sau la schimbarea scopului]`

## 5. Șablon de notă de informare (nu se publică automat)

> **Notă — nu este text juridic final și nu afirmă conformitate.** Se
> completează de operator, se validează juridic și abia apoi se publică.

**Operator:** `[OPERATOR]`, `[ADRESA]`, `[CUI]`.
**Contact privacy:** `[CONTACT]`. **DPO:** `[DPO]` (dacă este desemnat).

**Ce măsurăm.** Numărători agregate ale comenzilor și sumelor din magazin
(comenzi create și plătite, valoare brută și netă, TVA, livrare, reduceri,
rambursări, produse vândute). Opțional, dacă și când va fi aprobat separat,
numărători agregate de pagini cerute și categorii de sursă (direct, căutare,
social, recomandare).

**Ce nu facem.** Nu folosim cookie-uri sau alte tehnologii de stocare în
browser pentru aceste numărători, nu creăm profiluri, nu identificăm persoane,
nu vindem și nu transmitem date către terți, nu facem reclame pe baza lor.

**Temeiuri.** Executarea contractului și interesul legitim de a ne administra
magazinul; pentru trafic, interes legitim, cu evaluare `[LIA_REF]`, doar după
aprobare.

**Categorii de date.** Sume și numărători; pentru trafic doar ziua, ora,
categoria de pagină și categoria de sursă. Fără IP, fără identificatori, fără
date de contact.

**Retenție.** Comenzi: `[RETENTION_COMERCE]` zile. Ledger de deduplicare:
`[RETENTION_LEDGER]` zile. Trafic (dacă este aprobat): `[RETENTION_TRAFIC]`
zile. Apoi datele sunt șterse automat.

**Destinatari.** Niciunul extern: datele rămân în WordPress, la găzduirea
operatorului `[HOSTING]`.

**Drepturi.** Acces, rectificare, ștergere, restricționare, obiecție,
portabilitate, retragerea consimțământului (unde se aplică), plângere la
ANSPDCP (`https://www.dataprotection.ro`). Pentru numărătorile fără
identificatori, dreptul de obiecție nu poate fi aplicat individual; explicați
mecanismul disponibil `[MECANISM]` sau absența lui.

**Notă despre limitări.** Numărătorile de trafic sunt estimări: paginile din
cache/CDN nu sunt numărate, traficul automatizat poate rămâne parțial, referrer-ul
lipsă apare ca „direct”. Valorile mici sunt ascunse pentru a nu permite
identificarea indirectă.

## 6. Checklist DPO (bife înainte de orice activare)

- [ ] Harta datelor actualizată: tabele `adc_analytics_*`, scop, câmpuri, temei.
- [ ] LIA semnată pentru partea financiară; LIA separată pentru trafic, dacă se
      dorește vreodată activarea.
- [ ] Evaluare ePrivacy (acces terminal, log-uri server, cache/CDN) finalizată.
- [ ] Art. 21: mecanism decis și documentat (sau decizie explicită de
      necolectare). Fără mecanism → gate rămâne închis.
- [ ] Retenție configurată și verificată (`retention_traffic_days`,
      `retention_commerce_days`, `retention_ledger_days` ≥ commerce).
- [ ] `commerce_ack` bifat doar după citirea definițiilor financiare.
- [ ] Prag de celulă mică (`min_cell_count`) decis; comportamentul de suprimare
      complementară înțeles și acceptat.
- [ ] Acces la export limitat la administratori (`manage_options`) și
      `consent.delete_data_on_uninstall` decis explicit.
- [ ] Jurnale: `ADC_Logger` nu conține PII în export; se verifică periodic.
- [ ] Contract de găzduire/împuternicire (Art. 28) și locația datelor
      confirmate; fără transferuri suplimentare.
- [ ] Screening DPIA: decis dacă este necesară (profilare? date sensibile? scară?).
- [ ] Nota de informare publicată **după** validare juridică, cu placeholder-ele
      completate.
- [ ] Test de ștergere: dezinstalare cu opțiunea activă → tabelele dispar.
- [ ] Test de oprire: gate închis → nicio scriere nouă, nicio eroare în front.
- [ ] Data revizuirii și responsabilul consemnate.

## 7. Riscuri reziduale cunoscute

1. **Re-identificare prin combinații**: agregate pe zi × oră × tip de pagină ×
   ID de obiect pot deveni rare; pragul de celulă mică și suprimarea
   complementară reduc, dar nu elimină riscul.
2. **Numărare incompletă**: cache/CDN/proxy și prerender pot subestima serios;
   cifrele nu trebuie folosite pentru obligații contractuale sau financiare.
3. **Diferențe față de WooCommerce Analytics**: formulele sunt proprii; o
   diferență este raportată de `reconciliation()`, nu corectată automat.
4. **Ledger cu `order_id`**: este date personale indirecte (poate fi legat de o
   comandă). Retenția lui este legată de cea comercială și nu este exportabil.
5. **Rambursări șterse/reverse** nu sunt suportate în v1; pot rămâne diferențe
   permanente până la reconciliere manuală.
6. **Log-uri de server** (în afara plugin-ului) pot conține IP-uri; evaluarea
   trebuie să acopere politicile de log ale găzduirii.
7. **Multisite**: tabelele sunt per site; nu există agregare la nivel de rețea,
   deci nu se creează un profil consolidat.

## 8. Semnături

| Rol | Nume | Decizie | Data |
|---|---|---|---|
| Operator | `[NUME]` | `[aprobat / respins / condiționat]` | `[DATA]` |
| DPO | `[NUME]` | `[aviz favorabil / aviz negativ / cu recomandări]` | `[DATA]` |
| Responsabil tehnic | `[NUME]` | `[implementat conform / diferențe]` | `[DATA]` |

**Până la completarea acestui tabel:** partea financiară poate fi activată doar
după `commerce_ack`; traficul, UTM, `add_to_cart` și `begin_checkout` rămân
blocate în cod, iar acest document nu poate fi citat ca dovadă de conformitate.
