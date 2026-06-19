# Sito web Laura Rossin

Sito statico ottimizzato per velocita, leggibilita e SEO locale.

## Struttura

- `index.html`: homepage con servizi, metodo, iniziative, FAQ e contatti.
- `servizi/`: pagine SEO dedicate a psicoterapia, coaching e psicologia del lavoro.
- `iniziative/`: pagina dedicata al progetto Mente Adolescente.
- `admin/`: CMS protetto da login per modificare testi, immagini, logo, sezioni e pagine.
- `prenotazioni.html`: pagina pubblica con calendario e form di prenotazione.
- `api/prenotazioni.php`: collegamento server-side a Google Calendar.
- `privacy-policy.html` e `cookie-policy.html`: pagine informative con testo segnaposto modificabile dal CMS.
- `content/site.json`: contenuti modificabili dal CMS e letti dal sito.
- `styles.css`: interfaccia responsive e palette coordinata.
- `robots.txt` e `sitemap.xml`: base tecnica per indicizzazione.

## CMS

Il CMS richiede un hosting con PHP e permessi di scrittura su `content/site.json` e `assets/uploads/`.

Accesso iniziale:

- URL: `/admin/`
- Nome utente: `laura`
- Password: `CambiareSubito!2026`

Cambia subito le credenziali dalla sezione "Account" prima della pubblicazione.

Il CMS e diviso in sezioni: Dashboard, Media, Homepage, Servizi, Pagine, Prenotazioni, SEO / Condivisione, Account e Impostazioni. Prima di ogni salvataggio contenuti viene creato un backup automatico in `content/backups/`.

Per la pubblicazione e consigliato configurare le credenziali tramite variabili ambiente:

- `CMS_USERNAME`
- `CMS_PASSWORD_HASH` generato con `password_hash` di PHP
- `CMS_SESSION_LIFETIME` opzionale, in secondi
- `CMS_MAX_LOGIN_ATTEMPTS` opzionale

Se il file `admin/config.php` contiene ancora il vecchio hash legacy, il CMS lo aggiorna automaticamente al primo login riuscito o al cambio password.

### Google Calendar per prenotazioni

Nel CMS, sezione "Prenotazioni", inserisci:

- Google Calendar ID del calendario di Laura.
- Fuso orario, normalmente `Europe/Rome`.
- JSON dell'account di servizio Google.

Il calendario Google deve essere condiviso con l'email `client_email` dell'account di servizio. Gli slot prenotabili sono solo gli eventi che contengono `#disp` nel titolo o nella descrizione. Quando uno slot viene prenotato, il sito aggiorna quello stesso evento, rimuove `#disp` e salva i dati del form nella descrizione.

## Pubblicazione

Per usare anche il CMS, pubblica la cartella su un hosting con PHP. Se il sito viene pubblicato come hosting puramente statico, le pagine resteranno visibili ma l'area `/admin/` non potrà salvare modifiche.

Dopo la pubblicazione è utile inviare la sitemap in Google Search Console e verificare che Google Business Profile sia completo e coerente con i dati presenti sul sito.
