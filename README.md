# Sito web Laura Rossin

Sito PHP con CMS, ottimizzato per velocita, leggibilita e SEO locale.

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

Il CMS richiede un hosting con PHP. Su Railway usa un volume persistente montato in `/data`.

Accesso iniziale:

- URL: `/admin/`
- Nome utente e password vengono scelti dall'amministratore. Non ci sono credenziali predefinite.

Il CMS e diviso in sezioni: Dashboard, Media, Homepage, Servizi, Pagine, Prenotazioni, SEO / Condivisione, Account e Impostazioni. Prima di ogni salvataggio contenuti viene creato un backup automatico in `content/backups/`.

Su Railway configura queste variabili del servizio:

- `CMS_USERNAME`
- `CMS_PASSWORD_HASH` generato con `password_hash` di PHP
- `CMS_SESSION_LIFETIME` opzionale, in secondi
- `CMS_MAX_LOGIN_ATTEMPTS` opzionale

Per generare l'hash senza inserire la password nella cronologia del terminale:

```sh
php -r '$password = rtrim(fgets(STDIN), "\r\n"); if (strlen($password) < 16 || strlen($password) > 72) exit(1); echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;'
```

Digita una password nuova da 16 a 72 byte e premi Invio. Inserisci il risultato, inclusi i `$`, in `CMS_PASSWORD_HASH`. Conserva la password in un gestore di password.

Se il file `admin/config.php` contiene ancora il vecchio hash legacy, il CMS lo aggiorna automaticamente al primo login riuscito o al cambio password.

### Google Calendar per prenotazioni

Nel CMS, sezione "Prenotazioni", inserisci:

- Google Calendar ID del calendario di Laura.
- Fuso orario, normalmente `Europe/Rome`.
- JSON dell'account di servizio Google.

Il calendario Google deve essere privato, dedicato e condiviso con l'email `client_email` dell'account di servizio con accesso in modifica. Gli eventi con orario e tag `#disp` nel titolo o nella descrizione sono candidati alla prenotazione. Gli eventi occupati che si sovrappongono escludono lo slot. Quando uno slot viene prenotato, il sito aggiorna quello stesso evento e imposta l'appuntamento come privato. In anteprima usa un calendario separato e dati fittizi. Le prenotazioni restano disattivate fino all'attivazione esplicita nel CMS: prima di usarle con dati reali, completa l'informativa e verifica quali informazioni sono necessarie.

## Pubblicazione

1. In Railway crea un servizio da **GitHub Repository** scegliendo `giulianomacro-rgb/sito-laura-rossin` e il ramo `main`. Se non compare, autorizza soltanto questo repository in **Configure GitHub App**.
2. Collega un volume dedicato a `/data`. I contenuti iniziali vengono copiati al primo avvio; i deploy successivi conservano contenuti, media, backup e impostazioni del CMS.
3. Imposta `CMS_USERNAME` e `CMS_PASSWORD_HASH` nelle variabili del servizio. Il CMS non accetta login senza configurazione.
4. In **Settings → Networking → Generate Domain**, genera un indirizzo Railway. Verifica `/`, `/admin/`, `/prenotazioni.html` e `/healthz`.

Il deploy Railway usa PHP e Apache; la versione di prova invia `noindex` e non cambia il dominio ufficiale. Imposta `SITE_INDEXABLE=1` solo sul servizio collegato al dominio ufficiale. Su altri hosting PHP mantieni anche la cartella `lib/` e i file `.htaccess`.

La configurazione di infrastruttura e in `.railway/railway.ts`. Installa le dipendenze di sviluppo con `pnpm install --frozen-lockfile`, poi usa `railway config plan` prima di `railway config apply`. Le variabili riservate restano gestite su Railway. Il volume esistente `/data` deve rimanere collegato: non applicare un piano che lo rimuove. Il codice del sito si aggiorna automaticamente dai push su `main`; le modifiche all'infrastruttura richiedono invece `config apply`.

Dopo la pubblicazione è utile inviare la sitemap in Google Search Console e verificare che Google Business Profile sia completo e coerente con i dati presenti sul sito.

## Rendering e controlli

Su Railway PHP 8.4 rende sul server i contenuti del CMS, i metadati, i dati strutturati e la sitemap. La versione locale HTML continua a caricare il JSON con JavaScript. Gli articoli non pubblicati restituiscono 404 sul server. Il file `AUDIT-SEO.md` raccoglie interventi e verifiche editoriali ancora necessarie.

Controlli automatici: `php tests/render.php`, `php tests/booking.php`, `node --check cms-content.js`, `node --check prenotazioni.js`. Per un'anteprima PHP: `php -S 127.0.0.1:4175 tools/preview.php`.
