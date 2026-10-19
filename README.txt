=== ADC Cookies & Commerce Hub ===
Contributors: Dan Mutu
Tags: woocommerce, marketing, consent, gdpr, cookies, email, sms
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 9.5
WC tested up to: 11.2
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestionează cookies, consimțământul vizitatorilor și preferințele de marketing. Centralizează integrările de tracking și rapoartele comerciale WooCommerce, cu controale distincte pentru colectarea datelor.

== Description ==

ADC Cookies & Commerce Hub este un plugin WordPress care gestionează cookies, consimțământul vizitatorilor și preferințele de marketing în WooCommerce. Plugin-ul oferă:

* **Checkbox opțional la finalizarea comenzii**: clientul bifează voluntar acordul pentru e-mailuri de marketing și promoții prin SMS. Căsuța este nebifată implicit și nu blochează plasarea comenzii. Se afișează la checkout-ul clasic; checkout-ul pe blocuri (Store API) nu afișează încă această căsuță.
* **Preferințe de marketing în contul clientului**: pagina "Marketing" din "Contul meu" permite gestionarea separată a e-mailului și a SMS-ului, oricând.
* **Mesaj de consimțământ personalizabil**: administratorul editează textul afișat la checkout, cu shortcode-ul [privacy-policy-link] pentru linkul către Politica de Confidențialitate.
* **Export CSV**: administratorul exportă lista clienților care au consimțit sau nu, cu filtru opțional pe perioadă.
* **Vizibilitate în administrare**: statusul consimțământului apare în detaliile comenzii, în profilul utilizatorului și ca o coloană în lista comenzilor.
* **Filtru de comenzi**: comenzile se filtrează după consimțământul de marketing, atât cu stocarea clasică, cât și cu HPOS.
* **Instrumente GDPR**: date exportabile și ștergibile din uneltele WordPress de confidențialitate (Unelte > Date personale).
* **Banner de cookie-uri**: banner cu patru categorii (necesare, preferințe, statistici, marketing), cu butoane "Acceptă tot" și "Refuză tot" de aceeași importanță, în ordinea Acceptă · Setări · Respinge. Semnalele Google Consent Mode v2 și respectarea Global Privacy Control sunt incluse. Fiecare decizie se înregistrează într-un jurnal de consimțământ.
* **Evenimente și GTM**: dataLayer pentru evenimentele de e-commerce, container Google Tag Manager și opțiunea de a trimite evenimentele prin Google Tag Manager server-side.
* **Integrări opționale**: Meta (Pixel și Conversions API), TikTok (Pixel și Events API), Google Analytics 4 (Measurement Protocol) și sGTM. Fiecare se activează separat, din "ADC Cookies & Commerce Hub", și respectă starea consimțământului vizitatorului. Opțiunea GA4 "trimite și când e refuzat" este dezactivată implicit.
* **Potrivire avansată**: e-mailul și telefonul clientului sunt trimise hash-uite (SHA-256) către platforme, doar cu consimțământ de marketing.
* **Jurnale și diagnostic**: coada de evenimente, starea trimiterilor și mesajele de depanare sunt vizibile în "ADC Cookies & Commerce Hub > Jurnale & Debug".
* **Actualizări self-hosted**: verificarea și instalarea din GitHub Releases au loc în contexte de administrare (pagina "Pluginuri", inclusiv AJAX), la programările interne (cron) sau din linia de comandă (WP-CLI) — niciodată în timpul vizitelor pe site. Arhiva oficială `adc-marketing-X.Y.Z.zip` este verificată cu SHA-256. La verificare/descărcare, GitHub vede adresa IP a serverului și un User-Agent static; nu se trimite altă telemetrie.
* **Fără servicii externe implicite**: datele rămân în baza de date a site-ului. Trimiterea către Meta, TikTok, GA4 sau sGTM are loc doar dacă administratorul activează integrarea respectivă.

== Installation ==

1. Descărcați arhiva și încărcați directorul `adc-marketing` în `/wp-content/plugins/`, sau instalați-o din "Pluginuri > Adaugă".
2. Activați plugin-ul din meniul "Pluginuri".
3. Asigurați-vă că WooCommerce 9.5 sau mai nou este instalat și activat.
4. Accesați "ADC Cookies & Commerce Hub" din meniul de administrare pentru a personaliza mesajul și a exporta datele.

== Frequently Asked Questions ==

= Este necesar WooCommerce? =

Da. Plugin-ul necesită WooCommerce 9.5 sau mai nou.

= Căsuța de consimțământ este obligatorie? =

Nu. Consimțământul de marketing trebuie să fie voluntar, așa că plasarea comenzii nu depinde de bifarea căsuței.

= Cum schimb mesajul de consimțământ? =

Accesați "ADC Cookies & Commerce Hub" > "Mesaje marketing & Export", editați textul în editor și apăsați "Salvează setările". Shortcode-ul [privacy-policy-link] se înlocuiește automat cu linkul către pagina setată ca Politică de Confidențialitate.

= Cum exportez lista clienților? =

Accesați "ADC Cookies & Commerce Hub" > "Mesaje marketing & Export". Opțional, completați "Data început" și "Data sfârșit", apoi apăsați "Exportă utilizatori care au consimțit" sau "Exportă utilizatori care NU au consimțit". Fișierul CSV conține e-mailul, telefonul, numele și prenumele, iar numele fișierului conține data exportului.

= Pot retrage consimțământul? =

Da. Clienții pot modifica preferințele de e-mail și SMS din pagina "Marketing" din contul lor, oricând.

= Ce se întâmplă cu datele unui client care cere ștergerea? =

Folosiți "Unelte > Exportă date personale" sau "Unelte > Șterge date personale" din WordPress. Plugin-ul include un exportator și un ștergător pentru preferințele de marketing. Ștergerea contului unui utilizator elimină și aceste date.

= Ce înseamnă "HPOS"? =

High-Performance Order Storage, sistemul de stocare a comenzilor din WooCommerce. ADC Cookies & Commerce Hub funcționează cu ambele variante: cu stocarea clasică și cu HPOS.

= Cum actualizez plugin-ul? =

Din ecranul "Pluginuri" din WordPress, după ce plugin-ul este activ (verificarea rulează și la cron și din WP-CLI; vizitatorii nu declanșează nicio cerere). Actualizările provin exclusiv din GitHub Releases (repository-ul `dany547/adc-cookies-commerce-hub-wordpress`). La verificare și descărcare, GitHub vede IP-ul serverului și un User-Agent static al plugin-ului; nu se trimite altfel de telemetrie, iar cererile către WordPress.org pentru acest plugin nu sunt folosite.

== Changelog ==

= 2.2.0 =
* Plugin-ul se numește acum "ADC Cookies & Commerce Hub". Denumirea afișată în meniul de administrare și în titlurile paginilor s-a schimbat; slug-ul paginii, text domain-ul `adc-marketing`, opțiunile, tabelele și endpointurile rămân identice, deci setările și datele existente nu sunt afectate.
* Actualizări self-hosted din GitHub Releases: pagina "Pluginuri" verifică noul release stabil și afișează butonul de actualizare; descărcarea și instalarea se fac din arhiva oficială `adc-marketing-X.Y.Z.zip`, cu verificare SHA-256.
* Verificarea și descărcarea de actualizări au loc doar în ecranul de administrare plugin-uri și la programările interne (cron), nu în timpul vizitelor pe site. Nu se trimite niciun fel de telemetrie către GitHub.
* Pachetul de release are root-ul `adc-marketing/`, exact ca instalarea clasică, deci dezarhivarea înlocuiește plugin-ul fără pași suplimentari.

= 2.1.0 =
* Butoanele bannerului apar implicit în ordinea Acceptă · Setări · Respinge, iar ordinea poate fi schimbată din tabul "Banner consimțământ". Ordinea din pagina de administrare este și ordinea din codul HTML, deci navigarea cu Tab-ul și cititoarele de ecran rămân identice cu ce vede vizitatorul.
* Spațiu configurabil între "Acceptă" și celelalte două butoane, de la 0 la 48 de pixeli, implicit 16. Bannerul limitează spațiul la 12% din rând și ține cont de el la lățimea minimă a butoanelor, deci spațiul nu poate rupe niciodată linia; pe ecrane înguste devine spațiu vertical.
* Verificare de conformitate a culorilor: dacă butonul "Refuză" se vede mai greu decât "Acceptă" (fundal apropiat de fundalul bannerului, text nelizibil sau diferență mare de proeminență), tabul afișează panoul "Verificare conformitate: proeminența butoanelor", iar la salvare administratorul primește un avertisment "Modificare posibil necompliantă". Verificarea rulează și în previzualizarea live, la fiecare modificare de culoare.
* Presetul "Culoare brand" nu mai picta și butonul "Refuză" în culoarea brandului; "Refuză" rămâne neutru, ca cele două opțiuni să poată fi deosebite.
* Câmpul "Culoare brand (accent)" se salvează dintr-o singură salvare și nu mai rămâne gol la instalările noi.
* Mărimea, poziția în rând și greutatea vizuală ale butoanelor "Acceptă" și "Refuză" rămân identice; diferă doar culoarea.
= 2.0.0 =
* Banner de cookie-uri: presetări, layout-uri și culori configurabile. Compatibilitate verificată cu WordPress 7.1, WooCommerce 11.2 și GA4 Measurement Protocol (prag de 48 h pentru evenimente).
* Funcții noi: banner de cookie-uri cu Consent Mode v2, evenimente dataLayer și GTM, integrări opționale (Meta, TikTok, GA4, sGTM), potrivire avansată cu date hash-uite și jurnale de diagnostic.
* Pagina de administrare este un singur ecran cu taburi, sub meniul "ADC Marketing". Mesajul de consimțământ, exportul și statusul GDPR s-au mutat în tabul "Mesaje marketing & Export".
* Ștergerea de date personale elimină și adresele IP vechi (1.0) de pe comenzi. Ștergerea opțională la dezinstalare curăță tabelele, opțiunile, programările și datele plugin-ului.
* Șablonul paginii "Marketing" din contul clientului poate fi suprascris din tema activă, în adc-marketing/myaccount/marketing-consent.php.
* Exportul CSV neutralizează și caracterele + și - de la începutul valorilor (de exemplu, numerele de telefon cu prefix internațional).
* Versiunea din antetul plugin-ului și constanta ADC_MARKETING_VERSION sunt 2.0.0, pentru ca browserele să încarce resursele noi.
* Coloana, filtrul și statusul de consimțământ funcționează și cu HPOS (WooCommerce > Comenzi).
* Exportul folosește linkuri cu nonce, nu un formular în interiorul paginii; intervalul de date este validat.
* Exportul CSV neutralizează valorile care ar putea fi interpretate ca formule în Excel.
* Consimțământul de marketing de la checkout salvează un hash SHA-256 al textului afișat și un hash cu cheie al IP-ului. IP-ul brut nu mai este salvat pentru comenzile noi.
* Exportatorul și ștergătorul GDPR sunt conectate la uneltele de date personale WordPress. Înainte, exportatorul era înregistrat pe un hook care nu funcționa.
* Pagina "Marketing" din contul clientului afișează confirmarea salvării corect și, dacă modulul de cookie-uri este activ, secțiunea "Preferințe cookie".
* Căsuța de la checkout rămâne opțională. Blocul de checkout (Store API) nu afișează încă această căsuță.

= 1.0.0 =
* Versiunea inițială
* Checkbox la finalizarea comenzii
* Gestionare preferințe în contul utilizatorului
* Personalizare mesaj consimțământ
* Export date utilizatori în CSV
* Vizualizare consimțământ în admin
* Filtrare comenzi după consimțământ

== Upgrade Notice ==

= 2.2.0 =
Rebrandire afișată ("ADC Cookies & Commerce Hub") și actualizări self-hosted din GitHub Releases. Nicio modificare de schemă de date; opțiunile, tabelele și preferințele existente rămân neschimbate.

= 2.1.0 =
Ordinea implicită a butoanelor devine Acceptă · Setări · Respinge, spațiul dintre butoane este configurabil, iar plugin-ul avertizează când culorile alese fac refuzul mai puțin vizibil decât acceptul. Datele și preferințele existente rămân neschimbate.

= 2.0.0 =
Actualizare fără modificări de comportament pentru clienți. Pagina de administrare s-a mutat în "ADC Marketing" > "Mesaje marketing & Export". Datele existente rămân neschimbate.

= 1.0.0 =
Versiunea inițială a plugin-ului.
