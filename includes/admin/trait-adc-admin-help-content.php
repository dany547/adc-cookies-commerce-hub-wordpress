<?php
/**
 * Long-form help copy shared by the ADC admin tabs.
 *
 * Every method returns HTML built from translated strings (safe inline tags
 * only — the output goes through ADC_Admin_Help, which applies wp_kses_post).
 * The strings live in PHP so they are extractable for translation; nothing is
 * hard-coded in JavaScript.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Help text catalogue for info-icon modals.
 */
trait ADC_Admin_Help_Content
{
    /**
     * What data leaves the site towards Meta (CAPI).
     */
    protected function help_meta_data(): string
    {
        return '<p>' . esc_html__('Când activați trimiterea server-side, fiecare eveniment urmărit de plugin (produse, coș, comandă) este trimis către Meta Conversions API de pe serverul dumneavoastră, nu din browserul vizitatorului.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce anume pleacă:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li><code>' . esc_html__('event_name, event_time, event_id', 'adc-marketing') . '</code> — ' . esc_html__('numele, momentul și un identificator unic pentru deduplicare cu pixelul din browser.', 'adc-marketing') . '</li>'
            . '<li><code>' . esc_html__('user_data', 'adc-marketing') . '</code> — ' . esc_html__('doar valorile hash SHA-256 ale e-mailului, telefonului, prenumelui și numelui (din tabul „Potrivire avansată”); datele în clar nu pleacă niciodată.', 'adc-marketing') . '</li>'
            . '<li><code>' . esc_html__('custom_data', 'adc-marketing') . '</code> — ' . esc_html__('valoarea și moneda comenzii, ID-urile produselor.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Fără consimțământ „marketing” de la vizitator, evenimentul nu pleacă deloc. Tokenul de acces este stocat criptat și nu mai este afișat niciodată în formular.', 'adc-marketing') . '</p>';
    }

    /**
     * What data leaves the site towards GA4.
     */
    protected function help_ga4_data(): string
    {
        return '<p>' . esc_html__('Evenimentele sunt trimise către Google Analytics 4 prin Measurement Protocol, direct de pe serverul dumneavoastră către endpoint-ul Google (regiunea UE sau globală, conform setării de mai jos).', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce pleacă:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li><code>' . esc_html__('client_id', 'adc-marketing') . '</code> — ' . esc_html__('identificatorul de browser din cookie-ul _ga, folosit doar dacă vizitatorul a acordat consimțământul pentru „statistici”.', 'adc-marketing') . '</li>'
            . '<li><code>' . esc_html__('user_data', 'adc-marketing') . '</code> — ' . esc_html__('doar hash-uri SHA-256 (e-mail, telefon, nume), dacă potrivirea avansată este activă și permisă pentru GA4.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Parametrii evenimentului: nume, valoare, monedă, lista de produse.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Fără consimțământ de statistici, evenimentele nu se trimit (cu excepția opțiunii explicite „trimite anonim”, care nu include niciun identificator). Cheia API se stochează criptat.', 'adc-marketing') . '</p>';
    }

    /**
     * What data leaves the site towards TikTok.
     */
    protected function help_tiktok_data(): string
    {
        return '<p>' . esc_html__('fiecare eveniment urmărit de plugin pleacă de pe server către TikTok Events API 2.0. Pixelul din browser poate fi și el activat (mod „direct” sau prin GTM) pentru sesiunile cu consimțământ de marketing.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce pleacă:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li>' . esc_html__('Numele și momentul evenimentului, un event_id unic pentru deduplicare.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('E-mailul și telefonul, doar ca hash SHA-256 (niciodată în clar).', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Valoarea și moneda comenzii, datele despre produse.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Fără consimțământ „marketing”, evenimentele nu se trimit. Tokenul de acces se stochează criptat și nu se mai afișează după salvare.', 'adc-marketing') . '</p>';
    }

    /**
     * What Server GTM does and what "first-party" means here.
     */
    protected function help_sgtm(): string
    {
        return '<p>' . esc_html__('În mod „Transport”, eticheta gtm.js / gtag.js este încărcată de pe domeniul dumneavoastră (URL-ul salvat mai jos), nu de pe googletagmanager.com. Cookie-urile puse de container rămân astfel first-party și nu sunt blocate de protecțiile anti-tracking ale browsereelor (ITP).', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Evenimentele merg tot către destinațiile configurate în container (GA4, Meta etc.), dar mai întâi trec prin serverul GTM, unde puteți adăuga filtre și transformări.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Atenție:', 'adc-marketing') . '</strong> ' . esc_html__('funcția rămâne inactivă până salvați un URL https valid. Modul experimental „forward MP” nu este verificat oficial de Google — folosiți-l doar după test în DebugView.', 'adc-marketing') . '</p>';
    }

    /**
     * Advanced matching: PII collection + hashing explained.
     */
    protected function help_matching(): string
    {
        return '<p>' . esc_html__('Potrivirea avansată (matching) ajută platformele de advertising să recunoască un client între browser și server, deci să atribuie comenzile campaniilor corect.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce colectează:', 'adc-marketing') . '</strong> ' . esc_html__('e-mailul, telefonul, prenumele și numele de la câmpurile de checkout (sau din cont), în momentul completării.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Cum le stochează:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li>' . esc_html__('Datele în clar NU sunt salvate în baza de date și nu rămân în sesiune după trimitere.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Fiecare valoare este normalizată (micșor, fără spații, telefon în format internațional) și criptată SHA-256; doar hash-urile pleacă către platforme.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Un hash este ireversibil — platformele îl folosesc doar pentru comparare, nu pot reconstrui identitatea din el.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Trimiterea are loc doar pentru vizitatorii care au acordat consimțământul pentru categoria „marketing”. Dezactivați opțiunea dacă politica dumneavoastră de confidențialitate nu menționează acest proces.', 'adc-marketing') . '</p>';
    }

    /**
     * Consent categories explained (banner).
     */
    protected function help_consent_categories(): string
    {
        return '<p>' . esc_html__('Categoriile sunt alegerile pe care vizitatorii le văd în banner și în managerul de consimțământ. Doar „Necesare” este blocat pe „da” — fără ele site-ul nu funcționează (cookie de sesiune, security token).', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('Preferințe', 'adc-marketing') . '</strong> — ' . esc_html__('reține alegerile din banner și eventualele setări de limbă/aspect.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Statistici', 'adc-marketing') . '</strong> — ' . esc_html__('cookie-uri de analiză (ex. _ga); permite trimiterea evenimentelor către GA4. Refuzate: măsurarea server-side nu mai are client_id.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Marketing', 'adc-marketing') . '</strong> — ' . esc_html__('pixelii Meta/TikTok și trimiterea evenimentelor către platformele advertising. Refuzate: niciun eveniment de marketing nu pleacă.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Textul și numele de aici apar în modalul vizitatorului. Numele contează din punct de vedere legal: folosiți denumiri care descriu ce face fiecare categorie. Dacă schimbați textele sau categoriile, bifați „Cere din nou consimțământul” (policy bump) ca vizitatorii să fie întrebați din nou.', 'adc-marketing') . '</p>';
    }

    /**
     * Banner presets + colour fields explained.
     */
    protected function help_banner_colors(): string
    {
        return '<p>' . esc_html__('Preseturile sunt palete complete, gata configurate (Luminos, Întunecat, Culoare brand, Minimal, Contrast ridicat). Alegeți unul, apoi ajustați individual dacă e nevoie.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('La modificarea oricărei culori, presetul trece automat pe „Particularizat”. „Resetează la preset” revine la valorile presetului selectat.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Regulă GDPR de care țineți cont:', 'adc-marketing') . '</strong> ' . esc_html__('butoanele „Acceptă” și „Refuză” trebuie să rămână la fel de vizibile — aceeași mărime și greutate vizuală, diferă doar culoarea. Panoul de conformitate de mai jos vă avertizează dacă paleta curentă încalcă acest principiu sau dacă textul are contrast sub 4,5:1 (WCAG AA).', 'adc-marketing') . '</p>';
    }

    /**
     * Banner live preview explained.
     */
    protected function help_banner_preview(): string
    {
        return '<p>' . esc_html__('Previzualizarea reproduce bannerul real, cu textele, culorile și poziția selectate, modificat live când editați câmpurile. Butoanele din previzualizare nu sunt clicabile — testează pe site-ul publicat după salvare.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Fereastra „Fundal întunecat” apare doar pentru layoutul „Fereastră centrată”.', 'adc-marketing') . '</p>';
    }

    /**
     * Cookie inventory format explained.
     */
    protected function help_cookie_inventory(): string
    {
        return '<p>' . esc_html__('Lista apare public în managerul de consimțământ (tabul „Cookie-uri”) și ajută la conformitate: fiecare cookie declarat trebuie chiar să existe pe site.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Format, un cookie pe linie, câmpuri separate de „ | ”:', 'adc-marketing') . '</p>'
            . '<p><code>_ga | statistics | Google Analytics | Durare 13 luni | da</code></p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('categorie', 'adc-marketing') . '</strong> — ' . esc_html__('necessary, preferences, statistics sau marketing.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('prefix „da” sau *', 'adc-marketing') . '</strong> — ' . esc_html__('pentru cookie-uri dinamice (ex. _ga_* se scrie _ga*).', 'adc-marketing') . '</li>'
            . '</ul>';
    }

    /**
     * Marketing CSV export explained.
     */
    protected function help_export(): string
    {
        return '<p>' . esc_html__('Exportul generează un fișier CSV cu patru coloane: email, telefon, prenume, nume, pentru utilizatorii înregistrați care au consimțit (respectiv NU au consimțit) marketingul la finalizarea comenzii.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce se întâmplă când exportați:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li>' . esc_html__('Selectați „Exportă utilizatori care au consimțit” pentru a obține lista de contacte permise pentru campanii e-mail/SMS.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Lista „fără consimțământ” se exportă pentru a dovedi că NU le trimiteți mesaje — nu este o listă de trimitere.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Datele pleacă doar ca fișier descărcat în browserul dumneavoastră; nimic nu se trimite către servicii externe.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Intervalele de dată filtrează după data consimțământului; dacă nu setați date, se exportă tot.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Accesul este limitat la administratori (capability manage_options + nonce); păstrați fișierul CSV în siguranță, conține date personale.', 'adc-marketing') . '</p>';
    }

    /**
     * Checkout consent message editor explained.
     */
    protected function help_consent_message(): string
    {
        return '<p>' . esc_html__('Acest text se afișează lângă bifa de marketing de pe pagina de checkout (WooCommerce). Vizitatorul trebuie să bifeze obligatoriu pentru a-și da consimțământul — caseta nu este bifată implicit.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Shortcode-ul [privacy-policy-link]Text[/privacy-policy-link] se transformă într-un link către pagina de Politică de Confidențialitate setată în WordPress. Textul salvat este versionat: la fiecare comandă se stochează și o mostră din el, ca probă a consimțământului.', 'adc-marketing') . '</p>';
    }

    /**
     * Outbox journal: what is stored, retention, statuses.
     */
    protected function help_outbox(): string
    {
        return '<p>' . esc_html__('Jurnalul (outbox) este coada internă de expediere: fiecare eveniment generat de plugin primește o înregistrare pe platformă (meta, ga4, tiktok), care se procesează prin Action Scheduler.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce stochează:', 'adc-marketing') . '</strong> ' . esc_html__('numele evenimentului, platforma, ID-ul comenzii (dacă există), payload-ul JSON trimis, starea, numărul de încercări, codul HTTP primit și ultimul mesaj de eroare. În payload apar doar hash-uri SHA-256, nu date personale în clar.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Stări:', 'adc-marketing') . '</strong> «' . esc_html__('În așteptare', 'adc-marketing') . '» — ' . esc_html__('de trimis;', 'adc-marketing') . ' «' . esc_html__('Trimis', 'adc-marketing') . '» — ' . esc_html__('confirmat de platformă;', 'adc-marketing') . ' «' . esc_html__('Eșuat definitiv', 'adc-marketing') . '» — ' . esc_html__('după epuizarea reîncercărilor;', 'adc-marketing') . ' «' . esc_html__('Omis', 'adc-marketing') . '» — ' . esc_html__('fără consimțământ sau integrare dezactivată.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Retenție:', 'adc-marketing') . '</strong> ' . esc_html__('rândurile „Trimis” se șterg după 14 zile, cele „Eșuat definitiv” după 30 de zile (modificabil din tabul „Evenimente & GTM”). Butonul „Curăță jurnalul” aplică imediat aceste limite. Conținutul jurnalului este vizibil doar administratorilor.', 'adc-marketing') . '</p>';
    }

    /**
     * Consent lookup by cid explained.
     */
    protected function help_consent_lookup(): string
    {
        return '<p>' . esc_html__('Cid-ul (consent ID) este identificatorul anonim, format UUID, atașat consimțământului browserului unui vizitator. Îl găsiți în cookie-ul de consimțământ sau în jurnalul de evenimente al unei sesiuni.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Căutarea afișează starea efectivă a deciziilor pentru un cid (acordat/refuzat per categorie, sursă, timestamp), pentru investigarea unei solicitări a clientului („de unde știți că am acceptat?”). Nu se afișează date personale.', 'adc-marketing') . '</p>';
    }

    /**
     * Send test event explained.
     */
    protected function help_test_event(): string
    {
        return '<p>' . esc_html__('Butonul trimite un eveniment sintetic de test prin pipeline-ul real (coadă → verificare consimțământ → platforme activate), ca să verificați cap-coadă funcționarea, fără să plasați o comandă.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Folosește consimțământul browserului DUMNEAVOASTRĂ: dacă ați refuzat categoriile în banner, testul va fi omis în mod intenționat. Evenimentul apare în jurnal ca oricare altul — curățați jurnalul după test.', 'adc-marketing') . '</p>';
    }

    /**
     * GTM container + load mode explained.
     */
    protected function help_gtm_mode(): string
    {
        return '<p>' . esc_html__('ID-ul container (ex. GTM-ABC123) determină pluginul să insereze snippet-ul oficial GTM în antetul paginii. Dacă lăsați câmpul gol, containerul nu se încarcă deloc — evenimentele merg doar server-side.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Modurile de încărcare:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('Întotdeauna (cu setări implicite)', 'adc-marketing') . '</strong> — ' . esc_html__('containerul se încarcă imediat, dar tag-urile de marketing/statistici rămân blocate de Consent Mode până la decizia vizitatorului.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Doar după consimțământ', 'adc-marketing') . '</strong> — ' . esc_html__('scriptul gtm.js este cerut de la server abia după ce vizitatorul acceptă statistici sau marketing; variantă mai strictă, dar primul eveniment poate lipsi.', 'adc-marketing') . '</li>'
            . '</ul>';
    }

    /**
     * Tracked events list explained.
     */
    protected function help_events_list(): string
    {
        return '<p>' . esc_html__('Doar evenimentele bifate sunt generate: ele apar în dataLayer (pentru tag-urile din GTM) și, dacă sunt permise de consimțământ, în coada de trimitere server-side către Meta/GA4/TikTok. Debifați un eveniment ca să nu mai fie măsurat deloc.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Exemple:', 'adc-marketing') . '</strong> <code>view_item</code> — ' . esc_html__('vizualizare pagină produs;', 'adc-marketing') . ' <code>add_to_cart</code> — ' . esc_html__('adăugare în coș;', 'adc-marketing') . ' <code>purchase</code> — ' . esc_html__('comandă plătită (cel mai important pentru reclame);', 'adc-marketing') . ' <code>generate_lead</code> — ' . esc_html__('trimitere formular (newsletter/contact).', 'adc-marketing') . '</p>'
            . '<p><code>server_page_view</code> ' . esc_html__('trimite page_view și din server, nu doar din browser; măsurarea server-side este mai puțin blocată de browsere — porniți-o doar dacă vreți să comparați traficul browser vs. server.', 'adc-marketing') . '</p>';
    }

    /**
     * Item ID source + value semantics explained.
     */
    protected function help_item_id_source(): string
    {
        return '<p>' . esc_html__('ID-ul produsului (item_id) ajunge la platforme ca identificator în rapoartele de catalog. Alegeți ce folosește magazinul în feedurile existente:', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('ID WooCommerce', 'adc-marketing') . '</strong> — ' . esc_html__('implicit, numărul intern al produsului.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('SKU', 'adc-marketing') . '</strong> — ' . esc_html__('codul de stoc, dacă feedul Meta/TikTok folosește SKU.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('ID GLA', 'adc-marketing') . '</strong> — ' . esc_html__('pentru magazine conectate prin Google Listings & Ads.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Schimbați doar dacă feedul și evenimentele folosesc același identificator, altfel rapoartele nu se vor potrivi.', 'adc-marketing') . '</p>';
    }

    /**
     * Value semantics explained.
     */
    protected function help_value_mode(): string
    {
        return '<p>' . esc_html__('Determină ce sumă se raportează ca „value” pentru produse: prețul unitar fără TVA și fără livrare (recomandat de Google pentru GA4) sau prețul cu TVA inclus (uzual în rapoartele din România).', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Totalul coșului (cu tot cu taxe și livrare) este trimis oricum separat ca adc.order_total pentru Meta/TikTok, deci această setare nu schimbă venitul raportat per comandă pe acele platforme. Schimbarea se vede în rapoarte începând cu evenimentele noi.', 'adc-marketing') . '</p>';
    }

    /**
     * Excluded roles explained.
     */
    protected function help_exclude_roles(): string
    {
        return '<p>' . esc_html__('Vizitatorii logați cu unul dintre rolurile bifate aici nu sunt măsurați deloc (nici în dataLayer, nici server-side). Implicit: administrator și manager de magazin — astfel testele și comenzile de probă interne nu umflă rapoartele de conversii.', 'adc-marketing') . '</p>';
    }

    /**
     * Outbox retention settings explained.
     */
    protected function help_outbox_retention(): string
    {
        return '<p>' . esc_html__('Câte zile păstrați în jurnalul de evenimente intrările deja trimise (sent) și pe cele eșuate definitiv (dead). Până la aceste limite puteți reîncerca manual sau inspecta erori;', 'adc-marketing') . ' <strong>' . esc_html__('după limită, rândurile se șterg automat (cron zilnic).', 'adc-marketing') . '</strong></p>'
            . '<p>' . esc_html__('Setați valori mai mari dacă reconciliați rapoartele cu platformele lunar; mai mici dacă vreți un jurnal mai puțin voluminos.', 'adc-marketing') . '</p>';
    }

    /* --------------------------------------------------------------------- *
     * Analytics (setări) + Dashboard — texte pentru componentul info-icon.
     * Adăugate de chunk-ul analytics; metodele de mai sus aparțin celorlalte tab-uri.
     * --------------------------------------------------------------------- */

    /**
     * Raportarea comercială: ce se numără, ce nu, de ce nu sunt date personale, ce înseamnă confirmarea.
     */
    protected function help_analytics_commerce(): string
    {
        return '<p>' . esc_html__('Acest raport numără comenzile magazinului din WooCommerce și le adună pe zile: câte comenzi au ajuns în stările bifate mai jos și cât valorează. Totul rămâne în baza de date WordPress și nu este trimis nicăieri.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce se numără:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li>' . esc_html__('comenzi create (orice stare) și comenzi plătite (stările bifate de dumneavoastră);', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('venit brut, TVA, cost de livrare, reduceri și rambursări, separate pe monede;', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('valoarea medie a unei comenzi plătite și cantitățile de produse vândute.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p><strong>' . esc_html__('Ce NU se stochează:', 'adc-marketing') . '</strong> ' . esc_html__('nume, e-mail, telefon, adresă de livrare, adresă IP, cookie sau identificator de client. Rândurile agregate conțin doar data, moneda, sumele și niște contoare. ID-ul de comandă apare numai în registrul intern de deduplicare, ca să nu numărăm aceeași comandă de două ori.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('De ce considerăm acestea date fără caracter personal: o comandă este tranzacția magazinului, nu un profil de navigare. Dintr-un rând de tipul „vineri, 3 comenzi, 1.250 lei” nu se poate identifica nicio persoană.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce înseamnă confirmarea definițiilor:', 'adc-marketing') . '</strong> ' . esc_html__('prin bifa de confirmare declarați că acceptați formulele folosite aici (venit brut = suma comenzilor plătite, venit net = brut minus rambursări) și perioadele de retenție de mai jos. Fără această bifă, raportarea rămâne oprită, chiar dacă ați activat prima căsuță.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Nu vă așteptați la cifre identice cu WooCommerce Analytics sau cu rapoartele Meta/GA4: acest modul are definițiile sale și nu scade comisioanele procesatorului.', 'adc-marketing') . '</p>';
    }

    /**
     * Stări considerate plătite.
     */
    protected function help_analytics_paid_statuses(): string
    {
        return '<p>' . esc_html__('Aici alegeți ce stări WooCommerce înseamnă „comandă plătită” în rapoarte. O comandă se numără o singură dată, la prima trecere într-una dintre stările bifate; trecerile ulterioare nu o adaugă din nou.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Exemplu:', 'adc-marketing') . '</strong> ' . esc_html__('dacă bifați doar „Finalizate”, o comandă aflată în „În prelucrare” nu apare la venit până nu devine „Finalizate”. Dacă bifați ambele stări, comanda este numărată imediat ce intră în „În prelucrare”.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Rambursările nu scad numărul de comenzi plătite: ele apar separat, iar venitul net se calculează brut minus rambursări.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Dacă stările de aici diferă de stările folosite pentru evenimentul „purchase” din marketing (Meta/TikTok/GA4), cele două module vor raporta cifre diferite pentru aceeași perioadă. Este de așteptat, nu este o greșeală de calcul.', 'adc-marketing') . '</p>';
    }

    /**
     * Retenția agregatelor financiare și a registrului de deduplicare.
     */
    protected function help_analytics_retention_finance(): string
    {
        return '<p><strong>' . esc_html__('Retenție agregate financiare:', 'adc-marketing') . '</strong> ' . esc_html__('câte zile păstrați sumele și contoarele pe zile (implicit 730, adică 2 ani). După acest termen, rândurile mai vechi se șterg automat, prin cron, și dispar din rapoarte și din exporturile CSV.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Retenție registru de deduplicare:', 'adc-marketing') . '</strong> ' . esc_html__('câte zile păstrăm lista ID-urilor interne de comandă deja numărate. Registrul este cel care împiedică numărarea de două ori a aceleiași comenzi când starea se schimbă sau când o sarcină se reexecută. De aceea registrul nu poate fi păstrat mai puțin decât agregatele: altfel o comandă veche ar putea fi numărată din nou.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Alegeți perioade mai lungi dacă comparați anul curent cu anii trecuți; mai scurte dacă vreți o bază de date mai mică. Nicio setare de aici nu șterge comenzile din WooCommerce, ci doar agregatele acestui modul.', 'adc-marketing') . '</p>';
    }

    /**
     * Contorul de trafic: ce se numără, ce nu se colectează, semnalele de confidențialitate, politica.
     */
    protected function help_analytics_traffic(): string
    {
        return '<p>' . esc_html__('Contorul de trafic răspunde la o singură întrebare: câte accesări de pagină a primit site-ul într-o zi. Numărarea se face pe server, în momentul în care WordPress generează pagina.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce se stochează:', 'adc-marketing') . '</strong> ' . esc_html__('pe fiecare zi, un contor de accesări grupat după tipul de pagină (produs, articol, coș, cont…) și după canalul de proveniență (direct, căutare, rețea socială, trimitere de pe alt site, intern). Datele sunt agregate: „vineri, 40 de accesări pe o pagină de produs” — fără nicio legătură cu o persoană anume.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce NU se colectează niciodată:', 'adc-marketing') . '</strong></p>'
            . '<ul>'
            . '<li>' . esc_html__('adresa IP — nu este citită, nu este logată și nici nu este păstrată nici hash-uită;', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('cookie-uri sau identificatoare de browser/sesiune — modulul nu scrie și nu citește niciun cookie;', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('ID-ul de utilizator sau de client;', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('user agent-ul — este doar comparat cu un model de robot și apoi uitat; nu este stocat;', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('URL-ul referer — doar domeniul de origine este transformat într-un canal, el nu se salvează;', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('JavaScript de urmărire, amprentarea dispozitivului, parametrii UTM sau orice altă etichetă de campanie.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Semnalele de confidențialitate ale browserului sunt onorate: o cerere care trimite „Sec-GPC: 1” sau „DNT: 1” nu este numărată deloc. Pe lângă acestea, mai jos puteți exclude vizitele administratorilor autentificați și puteți opri filtrul de roboți.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Cifrele sunt estimative și, de regulă, subestimate: o pagină servită din cache sau CDN nu ajunge la PHP și nu este numărată, unii roboți pot rămâne în cifre, iar unii vizitatori reali pleacă înainte ca pagina să fie generată.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('De ce cere confirmarea Politicii de Confidențialitate:', 'adc-marketing') . '</strong> ' . esc_html__('deși nu există identificatori, aceasta este totuși o măsurare a audienței. Pornirea ei este decizia magazinului, luată după ce ați descris contorul în Politica de Confidențialitate (scop, temei, perioadă de păstrare, dreptul de obiecție). Textul de draft din acest tab vă poate ajuta la redactare, dar nu este un aviz juridic.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Oprirea contorului nu șterge istoricul: agregatele rămân până expiră perioada de retenție de mai jos.', 'adc-marketing') . '</p>';
    }

    /**
     * Retenția traficului.
     */
    protected function help_analytics_retention_traffic(): string
    {
        return '<p>' . esc_html__('Câte zile păstrați contoarele de trafic pe zi (între 30 și 180, implicit 90). Rândurile mai vechi de această perioadă se șterg automat, prin cron.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Alegeți 30–60 de zile dacă vă interesează doar tendința recentă; 180 de zile dacă vreți comparații sezoniere (de exemplu, aceeași lună din toamna trecută). O perioadă mai scurtă înseamnă și o bază de date mai mică.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Retenția nu are efect retroactiv peste datele deja șterse: dacă reduceți perioada, agregatele care ies din limită sunt eliminate la următorul run de curățare.', 'adc-marketing') . '</p>';
    }

    /**
     * Excluderi din numărare (administratori și roboți).
     */
    protected function help_analytics_exclusions(): string
    {
        return '<p>' . esc_html__('Ambele căsuțe se aplică doar după ce ați pornit contorul de trafic; ele nu modifică rapoartele financiare.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Nu număra vizitele administratorilor autentificați:', 'adc-marketing') . '</strong> ' . esc_html__('testele dumneavoastră, comenzile de probă și navigarea prin panoul de administrare nu umflă cifrele de audiență. Recomandăm să lăsați bifa activă.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Filtrare roboți conservatoare:', 'adc-marketing') . '</strong> ' . esc_html__('cererile al căror user agent seamănă cu un crawler (bot, crawl, spider, headless, preview, curl, wget…) sau care nu trimit deloc user agent nu sunt numărate. Filtrul este ales să fie simplu și cu puține fals-pozitive: unelte noi pot scăpa. Dacă debifați opțiunea, robotii sunt numărați și ei, deci cifrele devin mai umflate, nu mai corecte.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Indiferent de aceste setări, un browser care trimite „Sec-GPC: 1” sau „DNT: 1” nu este niciodată numărât.', 'adc-marketing') . '</p>';
    }

    /**
     * Pragul minim pentru celule.
     */
    protected function help_analytics_min_cell(): string
    {
        return '<p>' . esc_html__('Acesta este pragul de confidențialitate: orice celulă de raport (o zi, un canal, un tip de pagină, un produs) cu o valoare mai mică decât cifră de aici nu este afișată pe ecran și nu apare în exporturile CSV. Nu este înlocuită cu 0 — pur și simplu lipsește.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('De ce există: o celulă cu 1–2 evenimente poate fi recunoscută de cineva care știe deja despre ce comandă este vorba (de exemplu „produsul X, vineri, 1 bucată”). Cu un prag de 5, astfel de celule devin invizibile. Se aplică și listelor de produse și canale, deci și rapoartelor financiare, nu doar traficului.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Majorați valoarea pe un magazin mic (de exemplu 10); pe un magazin cu volume mari o puteți coborî spre 3. Totalurile de perioadă se compară cu același prag.', 'adc-marketing') . '</p>';
    }

    /**
     * Notițele interne (temei, referință, dată).
     */
    protected function help_analytics_internal_notes(): string
    {
        return '<p>' . esc_html__('Aceste trei câmpuri sunt o evidență internă: unde este documentul de evaluare (LIA), ce temei juridic ați propus și la data aprobării. Ele nu pornesc nicio colectare și nu înlocuiesc căsuțele de confirmare de mai sus.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Le puteți completa după ce evaluarea juridică este gata. Conținutul se salvează în opțiunile WordPress, este vizibil doar administratorilor cu drepturi de administrare și nu este trimis nicăieri.', 'adc-marketing') . '</p>';
    }

    /**
     * Cardul „Indisponibile în această versiune”.
     */
    protected function help_analytics_unavailable(): string
    {
        return '<p>' . esc_html__('Funcțiile de mai jos au cod și tabele în acest modul, dar porțile lor sunt închise în cod: nicio setare, filtru sau constantă nu le poate activa, iar interfața nu afișează comutatoare pentru ele, ca să nu sugereze că ar fi disponibile.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Motivul comun: este vorba despre profilare de marketing (cine a venit din ce campanie, ce a făcut înainte de comandă), nu despre operarea magazinului. Un astfel de demers cere un temei juridic separat, o evaluare ePrivacy pentru ce se scrie sau se citește în dispozitivul vizitatorului și, pentru multe magazine, consimțământ explicit.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Raportarea financiară și contorul de trafic anonim se activează din secțiunile 1 și 2; aici nu există nimic de bifat.', 'adc-marketing') . '</p>';
    }

    /**
     * Parametri UTM: ce ar face și de ce sunt indisponibili.
     */
    protected function help_analytics_utm(): string
    {
        return '<p>' . esc_html__('Un link de campanie arată așa: <code>?utm_source=facebook&utm_medium=cpc&utm_campaign=black-friday</code>. Cei trei parametri sunt etichete puse de publicitar în link și spun „ce platformă” (source), „ce tip de reclamă” (medium) și „ce campanie” (campaign).', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('Ce ar face modulul dacă ar fi activ:', 'adc-marketing') . '</strong> ' . esc_html__('ar citi acești parametri la prima pagină vizitată și i-ar lega de vizitatorul respectiv, ca să puteți spune „campania X a adus Y comenzi”. În practică asta înseamnă un identificator de campanie păstrat împreună cu datele clientului și, de regulă, un cookie sau un storage în browser.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('De ce este indisponibil acum:', 'adc-marketing') . '</strong> ' . esc_html__('atribuirea pe campanii este profilare de marketing: are nevoie de consimțământ separat, de un temei juridic și de o evaluare ePrivacy pentru ce se scrie în dispozitivul vizitatorului. Până atunci, modulul nici măcar nu citește parametrii UTM din URL: ei nu sunt stocați, logați sau transmiși nicăieri.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Canalul de proveniență (direct / căutare / rețea socială) din contorul de trafic este altceva: este derivat doar din domeniul referer, pe zi și agregat, fără nicio legătură cu un client sau cu o campanie.', 'adc-marketing') . '</p>';
    }

    /**
     * add_to_cart: ce ar face și de ce este indisponibil.
     */
    protected function help_analytics_add_to_cart(): string
    {
        return '<p>' . esc_html__('Ar însemna să numărăm de câte ori a fost adăugat fiecare produs în coș, pe zi, ca să vedeți ce articole stârnesc intenție de cumpărare înainte de plasarea comenzii.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('De ce este indisponibil acum:', 'adc-marketing') . '</strong> ' . esc_html__('adăugarea în coș este un semnal de intenție, folosit în mod normal pentru publicuri asemănătoare și retargetare, deci pentru marketing — are nevoie de un temei juridic separat și de consimțământ pe categoria respectivă. În plus, produsul poate fi adăugat și prin Store API / AJAX, iar un contor bazat doar pe hook-ul clasic WooCommerce ar fi incomplet, deci înșelător.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Ce aveți în schimb: topul produselor vândute, din comenzi plătite, este disponibil în Dashboard și este complet, pentru că pornește de la comanda fermă.', 'adc-marketing') . '</p>';
    }

    /**
     * begin_checkout: ce ar face și de ce este indisponibil.
     */
    protected function help_analytics_begin_checkout(): string
    {
        return '<p>' . esc_html__('Ar însemna să numărăm începerile de finalizare a comenzii, ca să estimăm abandonurile de coș (începuturi de checkout care nu au devenit comandă).', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('De ce este indisponibil:', 'adc-marketing') . '</strong> ' . esc_html__('nu există un punct unic, verificat pe server, care să semneze „vizitatorul a început finalizarea”. WooCommerce afișează pagina de checkout, dar nu emite un eveniment de început; numărarea ar însemna să transformăm o simplă vizualizare de pagină într-un eveniment de marketing. Orice abandon calculat așa ar fi o cifră inventată, nu una măsurată.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Prin urmare, metricile „abandon de coș”, „rată de conversie” și „vizitatori unici” nu pot fi produse de acest modul și nici nu sunt simulate în rapoarte.', 'adc-marketing') . '</p>';
    }

    /**
     * Secțiunea de retenție și dezinstalare.
     */
    protected function help_analytics_retention_section(): string
    {
        return '<p>' . esc_html__('Aici vedeți pe scurt ce perioade de păstrare sunt active acum. Le modificați din secțiunile de mai sus: financiar la 1, trafic la 2.', 'adc-marketing') . '</p>'
            . '<p><strong>' . esc_html__('La dezinstalare:', 'adc-marketing') . '</strong> ' . esc_html__('datele acestui modul se șterg doar dacă activați opțiunea existentă „Șterge datele la dezinstalare” din setările de consimțământ. Modulul nu adaugă un al doilea comutator, ca să nu existe două surse de adevăr.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Ștergerea vizează tabelele de agregate, registrul de deduplicare, opțiunile de setări și sarcinile de cron ale modulului. Comenzile din WooCommerce nu sunt atinse.', 'adc-marketing') . '</p>';
    }

    /**
     * Dashboard: panoul „Stare colectare”.
     */
    protected function help_analytics_gates(): string
    {
        return '<p>' . esc_html__('Aici vedeți, pe scurt, ce colectează site-ul chiar acum. Fiecare linie arată starea porției respective (Pornit sau Oprit) și motivul afișat de sistem.', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('Rapoarte financiare din comenzi', 'adc-marketing') . '</strong> — ' . esc_html__('pornit doar cu căsuța de activare și confirmarea definițiilor, ambele în Analytics (setări).', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Numărare trafic (estimativă)', 'adc-marketing') . '</strong> — ' . esc_html__('pornit doar cu căsuța de activare și confirmarea Politicii de Confidențialitate. Când este oprit, panourile de trafic afișează un mesaj clar, nu zero.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('UTM, adăugare în coș, începere finalizare', 'adc-marketing') . '</strong> — ' . esc_html__('indisponibile: nu există comutator pentru ele în această versiune.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('O modificare de setări se vede aici după reîncărcarea paginii; nimic nu este estimat retroactiv.', 'adc-marketing') . '</p>';
    }

    /**
     * Dashboard: panoul „Filtre raport”.
     */
    protected function help_analytics_filters(): string
    {
        return '<p>' . esc_html__('Filtrele schimbă perioada și latura din raport, nu datele în sine. Ele lucrează prin linkuri (URL), deci funcționează și fără JavaScript și pot fi trimise mai departe unui coleg.', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('Interval', 'adc-marketing') . '</strong> — ' . esc_html__('7/30/90 de zile încheiate azi, sau un interval personalizat ales cu Data început și Data sfârșit (între 1 și 180 de zile). Un interval personalizat are nevoie de ambele date, altfel se revine la presetul de 30 de zile.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Canale', 'adc-marketing') . '</strong> — ' . esc_html__('filtrează doar partea de trafic: afișează cererile de pagină venite din canalul ales. Nu se aplică comenzilor și veniturilor, pentru că un „venit pe canal” ar fi atribuire, iar acest modul nu face atribuire.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Monedă', 'adc-marketing') . '</strong> — ' . esc_html__('alege o singură monedă: sumele nu se adună peste monede. Implicit se folosește moneda magazinului.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Tip pagină, Listă, Produs (ID)', 'adc-marketing') . '</strong> — ' . esc_html__('restrâng listele și tabelele; le aplicați cu butonul „Aplică filtrele”.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Comparația cu aceeași perioadă dinainte apare doar pentru preseturile 7/30/90, nu pentru intervale personalizate.', 'adc-marketing') . '</p>';
    }

    /**
     * Dashboard: panoul „Cifre cheie” (definiții KPI).
     */
    protected function help_analytics_figures(): string
    {
        return '<p>' . esc_html__('Definițiile folosite de acest modul (sunt ale modulului, nu ale WooCommerce Analytics sau ale Google Analytics):', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('Comenzi create', 'adc-marketing') . '</strong> — ' . esc_html__('câte comenzi au fost plasate în perioadă, indiferent de stare, inclusiv cele anulate sau eșuate.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Comenzi plătite', 'adc-marketing') . '</strong> — ' . esc_html__('comenzile care au intrat în cel puțin una dintre stările bifate ca plătite în setări; o comandă se numără o singură dată.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Venit brut', 'adc-marketing') . '</strong> — ' . esc_html__('suma valorilor comenzilor plătite (cu tot cu taxe și livrare), în moneda selectată.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Venit net', 'adc-marketing') . '</strong> — ' . esc_html__('venitul brut minus rambursările din perioada respectivă.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Valoare medie comandă', 'adc-marketing') . '</strong> — ' . esc_html__('venit brut împărțit la numărul de comenzi plătite.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Cantități', 'adc-marketing') . '</strong> — ' . esc_html__('bucăți vândute pe comenzile plătite, fără produsele din comenzile anulate.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Cereri de pagină (estimat)', 'adc-marketing') . '</strong> — ' . esc_html__('numărarea traficului; este un volum separat, care nu se împarte la comenzi.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('De ce nu vedeți aici rată de conversie: ar trebui să împărțiți o estimare de trafic la comenzi reale, iar cei doi termeni nu măsoară aceeași populație — traficul are vizitatori, comenzile au clienți.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Un indicator poate apărea gol, nu cu 0. Înseamnă fie că toate celulele din perioadă sunt sub pragul de confidențialitate, fie că moneda aleasă nu are date în interval.', 'adc-marketing') . '</p>';
    }

    /**
     * Dashboard: panoul de reconciliere.
     */
    protected function help_analytics_reconciliation(): string
    {
        return '<p>' . esc_html__('Aici comparăm două surse care ar trebui să dea aceleași cifre: agregatele salvate de modul în ziua comenzii și totalurile recalculate acum, direct din comenzile existente.', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li><strong>' . esc_html__('Diferență 0', 'adc-marketing') . '</strong> — ' . esc_html__('agregatele pentru ziua respectivă sunt în regulă.', 'adc-marketing') . '</li>'
            . '<li><strong>' . esc_html__('Diferență diferită de 0', 'adc-marketing') . '</strong> — ' . esc_html__('comenzile s-au schimbat după ce fusese scris agregatul (o rambursare ulterioară, o comandă ștearsă, o schimbare de stare) sau agregatul a fost scris de o versiune mai veche a pluginului. Diferența se afișează pe metrică și pe zi.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('O diferență mai mare decât pragul de alertare este marcată cu „necesită atenție”. Nimic nu este corectat automat.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p><strong>' . esc_html__('Ce puteți face:', 'adc-marketing') . '</strong> ' . esc_html__('reconstruirea agregatelor pentru o perioadă recitește comenzile și suprascrie cifrele pe zile. Este o operațiune internă: nu trimite date nicăieri și nu modifică comenzile.', 'adc-marketing') . '</p>'
            . '<p>' . esc_html__('Până la reconstrucție, cifrele din Dashboard rămân cele din agregate; diferențele de aici nu se adaugă la venit.', 'adc-marketing') . '</p>';
    }

    /**
     * Dashboard: panoul „Atenționări și precizii”.
     */
    protected function help_analytics_warnings(): string
    {
        return '<p>' . esc_html__('Aici sunt limitările care atârnă cifrelor de mai sus. Le ținem pe același ecran ca să nu existe un raport fără note de subsol.', 'adc-marketing') . '</p>'
            . '<ul>'
            . '<li>' . esc_html__('Traficul este estimativ: paginile servite din cache/CDN nu ajung la PHP și nu sunt numărate; unii roboți pot rămâne în cifre, unii vizitatori reali pot lipsi.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Canalul „direct” înseamnă și „referer lipsă”, nu doar acces direct tastat; nu este atribuire.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Celulele sub pragul de confidențialitate sunt ascunse, nu înlocuite cu 0 — deci un total de listă poate fi mai mic decât totalul perioadei.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('Sumele sunt raportate pe o singură monedă și folosesc definițiile acestui modul, deci pot diferi de WooCommerce Analytics sau de rapoartele platformelor de reclamă.', 'adc-marketing') . '</li>'
            . '<li>' . esc_html__('O rambursare apare în ziua în care a fost făcută rambursarea, nu în ziua comenzii.', 'adc-marketing') . '</li>'
            . '</ul>'
            . '<p>' . esc_html__('Nicio atenționare de aici nu înseamnă că datele sunt greșite; înseamnă că trebuie citite cu limita respectivă în minte.', 'adc-marketing') . '</p>';
    }
}
