# ADC Marketing: ghid de utilizare

Acest ghid este pentru administratorii magazinului și pentru clienți. Descrie
funcțiile plugin-ului ADC Marketing 2.0.

## Pentru administratori

### Unde găsiți setările

Din meniul de administrare, alegeți **ADC Marketing**. Pagina are taburi. Pentru
consimțământul de marketing folosiți tabul **Mesaje marketing & Export**.

### Personalizarea mesajului de consimțământ

1. Accesați **ADC Marketing > Mesaje marketing & Export**.
2. Editați textul din câmpul de mesaj. Textul apare sub căsuța de bifat de la
   finalizarea comenzii.
3. Pentru a adăuga linkul către Politica de Confidențialitate, folosiți
   shortcode-ul `[privacy-policy-link]`. Linkul se completează automat cu pagina
   pe care ați setat-o în **Setări > Confidențialitate**.
4. Apăsați **Salvează setările**.

Căsuța este opțională și nebifată implicit. Clienții pot plasa comanda fără să
bifeze nimic. Nu este nevoie să modificați textul pentru asta.

### Exportul listei de clienți

1. Accesați **ADC Marketing > Mesaje marketing & Export**, secțiunea
   **Export bază de date consimțământ**.
2. Opțional, completați **Data început** și **Data sfârșit**. Dacă lăsați
   câmpurile goale, se exportă toți clienții.
3. Apăsați una dintre cele două opțiuni:
   - **Exportă utilizatori care au consimțit**: clienții cu consimțământ de marketing.
   - **Exportă utilizatori care NU au consimțit**: clienții care au refuzat.
4. Confirmați exportul dacă plugin-ul vă cere, când ați ales un interval de date.

Fișierul CSV conține e-mailul, telefonul, numele și prenumele. Are separator
punct și virgulă și poate fi deschis direct în Excel. Numele fișierului include
data exportului, de exemplu `utilizatori-consimtamant-2026-10-18.csv`.

Exportul este disponibil doar pentru utilizatorii cu rolul de administrator.

### Consimțământul pe o comandă

Pe pagina fiecărei comenzi, în partea de jos a detaliilor, apare secțiunea
**Consimțământ Marketing**. Aici vedeți dacă clientul a bifat căsuța, data și
textul pe care l-a văzut.

Lista comenzilor are coloana **Consimțământ Marketing**, cu un semn de
bifare pentru comenzile cu acord. Funcționează atât cu stocarea clasică, cât și
cu HPOS.

### Filtrarea comenzilor după consimțământ

În lista comenzilor, alegeți din meniul de filtrare:

- **Cu consimțământ marketing**
- **Fără consimțământ marketing**

Apăsați **Filtrează** pentru a vedea rezultatele.

### Consimțământul unui utilizator

În profilul utilizatorului (**Utilizatori > Editează**), secțiunea
**Consimțământ Marketing** arată consimțământul general, pentru e-mail și pentru
SMS, împreună cu data. Puteți modifica aceste valori și salva profilul.

### Banner, integrări și jurnale

Versiunea 2.0 adaugă module opționale, toate din meniul **ADC Marketing**:

- **Banner consimțământ** (tabul Banner): bannerul de cookie-uri, cu patru categorii
  și butoanele "Acceptă tot" și "Refuză tot", de aceeași importanță. Dacă modificați
  textele sau categoriile legale, puteți cere din nou consimțământul, din aceeași pagină.

  Butoanele apar implicit în ordinea **Acceptă · Setări · Respinge** și o puteți schimba
  din câmpul „Ordinea butoanelor”. Între „Acceptă” și celelalte două butoane puteți pune
  un spațiu de la 0 la 48 de pixeli, din câmpul „Spațiu după Acceptă”. Bannerul limitează
  singur spațiul la 12% din rând, ca să nu rupă linia, iar pe telefon, unde butoanele se
  așază unul sub altul, spațiul devine vertical.

  Culorile rămân libere, dar modulul le verifică: dacă „Refuză” ajunge să se vadă mai
  greu decât „Acceptă” (de exemplu un buton alb pe un banner alb), pagina de setări
  afișează un avertisment „Verificare conformitate: proeminența butoanelor”, iar la
  salvare primiți mesajul „Modificare posibil necompliantă”. GDPR cere ca refuzul să fie
  la fel de vizibil și de ușor de apăsat ca acceptul; un banner care ascunde refuzul
  poate face consimțământul obținut prin el nevalid. Avertismentul apare imediat,
  în previzualizarea live, de fiecare dată când modificați o culoare.
- **Evenimente și GTM** (tabul Evenimente & GTM): containerul Google Tag Manager și
  evenimentele de e-commerce trimise în dataLayer.
- **Integrări** (Meta, GA4, Server GTM, TikTok): fiecare platformă se activează separat.
  Nimic nu este trimis către platforme până nu activați o integrare.
- **Potrivire avansată** (tabul Potrivire avansată): e-mailul și telefonul clientului
  sunt trimise hash-uite, doar cu consimțământ de marketing.
- **Jurnale & Debug** (tabul Jurnale & Debug): coada de evenimente și starea
  trimiterilor. Nivelul de jurnalizare se alege din același tab.

### Cererile GDPR

Când un client cere datele sale personale:

1. Accesați **Unelte > Exportă date personale** și introduceți e-mailul clientului.
   Preferințele de marketing apar în fișierul generat, în grupul
   **ADC Marketing - consimțământ marketing**.
2. Pentru ștergere, accesați **Unelte > Șterge date personale**. Preferințele
   de marketing ale contului sunt eliminate. Comenzile rămân, pentru a păstra
   dovada consimțământului. Adresele IP vechi (din versiunile 1.0) se șterg de
   pe comenzile clientului.

## Pentru clienți

### Gestionarea preferințelor de marketing

Clienții pot schimba oricând preferințele din contul lor:

1. Conectați-vă și accesați **Contul meu**.
2. Alegeți **Marketing** din meniul din stânga.
3. Bifați sau debifați:
   - **Doresc să primesc comunicări de marketing prin e-mail**
   - **Doresc să primesc promoții prin SMS**
4. Apăsați **Salvează preferințele**. Confirmarea apare pe pagină.

Adresa paginii este `/my-account/marketing-consent/`. Dacă există și secțiunea
**Preferințe cookie**, din aceeași pagină puteți modifica și alegerile pentru
cookie-uri.

Retragerea consimțământului este la fel de simplă ca acordarea lui: debifați
căsuța și salvați.

## Note pentru integrări (când modulele de măsurare sunt active)

Aceste note se aplică doar dacă ați activat trimiterea de evenimente către
platforme. Setările se află în tabul de integrări.

- **Valoarea comenzii în Meta și TikTok**: evenimentul de achiziție trimite
  valoarea totală a comenzii, cu taxe și transport incluse. Pentru tag-urile
  din Google Tag Manager, mapați valoarea la variabila `adc.order_total`.
- **Achiziția în GA4**: implicit, evenimentul `purchase` pentru GA4 este trimis
  din browser. Nu îl trimiteți și din server decât dacă ați ales explicit această
  opțiune, iar în acel caz dezactivați trimiterea din browser.
