# Audit SEO e ricerca AI - 15 novembre 2026

## Correzioni implementate

- Le pagine pubbliche e gli articoli vengono serviti con i testi del CMS gia presenti nell'HTML. Non richiedono JavaScript per leggere i contenuti o seguire i link.
- Ogni articolo ha un URL canonico specifico. La sitemap viene generata dai contenuti pubblicati e include gli articoli; le pagine rimosse restituiscono 404.
- Titoli e descrizioni corrispondono al contenuto. H1, contatti, FAQ e testi visibili restano sincronizzati con il CMS.
- Dati strutturati Person, WebSite, WebPage, Service, Article e BreadcrumbList collegati da identificativi stabili. Nessuna recensione, tariffa, data di aggiornamento editoriale o approvazione clinica inventata. Rimossa la tariffa simbolica non verificata.
- Semplificata la scrittura della homepage. La ricerca "psicologo" e affrontata nelle risorse e nelle domande pertinenti senza alterare il titolo professionale di Laura.
- Le risposte sulla durata dei percorsi non promettono risultati o un numero fisso di incontri.
- La copia Railway resta noindex per evitare che l'anteprima competa con il sito ufficiale. Il dominio ufficiale rimane il riferimento canonico.
- Nessun file o istruzione per influenzare artificialmente le risposte AI. La priorita e rendere informazioni verificabili, leggibili e coerenti.

## Da confermare prima della messa in produzione sul dominio

- L'attuale sito pubblico riporta ancora Milano e il numero +39 376 1324261. Il nuovo progetto mantiene Bolzano e +39 346 6009503, gia presenti nei contenuti concordati. Uniformare anche le schede professionali e Google Business Profile dopo conferma di Laura.
- Far rivedere a Laura i contenuti clinici, il riferimento alla specializzazione e gli approfondimenti prima di indicizzarli. Non e stata attribuita una revisione clinica che non risulta effettuata.
- Completare l'informativa privacy e la configurazione Calendar prima di attivare le prenotazioni reali. L'integrazione resta disattivata per impostazione iniziale.
- Quando si collega il dominio ufficiale: impostare SITE_INDEXABLE=1 sul servizio definitivo, verificare URL canonici, inviare /sitemap.xml in Search Console e controllare copertura e query reali. Verificare i vecchi URL Wix e i reindirizzamenti necessari prima di sostituire il sito ufficiale.
- Il miglioramento tecnico non garantisce posizioni su Google o citazioni nei motori AI. Non sono state misurate impressioni, clic o conversioni: manca l'accesso a Search Console e alle statistiche del sito ufficiale.

## Verifiche della pubblicazione

- GitHub: ramo `main` collegato al servizio Railway `sito-laura-rossin`.
- Homepage Railway: risposta HTTP 200, titolo aggiornato e contenuti gia presenti nell'HTML. La risposta include `noindex, nofollow`, come previsto per questa copia di prova.
- Avvio Apache corretto e controllo di salute `/healthz` riuscito. Volume persistente collegato a `/data`; credenziali CMS escluse da Git e dall'immagine pubblicata.
- Test PHP: nove tipi di pagina, dati strutturati, articoli, escaping dei contenuti e filtri delle disponibilita Calendar.
- Test HTTP ripetuti sul sito Railway: 14 percorsi pubblici, errori 404, prenotazioni disattivate, login/logout CMS, protezione CSRF, salvataggio e rilettura degli stessi contenuti, upload JPEG e rifiuto SVG. Verificati anche i cookie Secure/HttpOnly/SameSite, il divieto di cache del CMS e la protezione dei percorsi riservati.
- Browser autorizzato: collaudo visivo desktop/mobile completato. Homepage controllata a 320, 390, 768, 1024, 1280 e 1440 pixel, dopo il caricamento dei font: nessuno sforamento del titolo o della pagina; cartellini professionali contenuti nel riquadro e separati dalla foto. Non e un test su dispositivi fisici.
- Verificati navigazione del menu, carosello iniziative, FAQ, pagina di servizio, articolo e pagina Prenota. Immagini pubbliche caricate correttamente; nessun errore JavaScript osservato nella homepage e nell'editor. La pagina Prenota segnala la disattivazione e nasconde il form.
- CMS remoto: accesso riuscito, selezione dei testi e delle immagini nell'editor, cambio pagina e salvataggio dal pulsante verificati. Testato anche su larghezze di 320 e 390 pixel senza scorrimento orizzontale.
- Corretti durante il collaudo: link delle iniziative sovrascritti dal rendering, altezza della prima sezione, dimensioni del titolo sui dispositivi stretti, campi nascosti nell'editor, associazione dei contenuti riordinati/nascosti, paragrafi ripetuti e URL delle anteprime immagini. La sessione scaduta produce un messaggio comprensibile e non consente il salvataggio (HTTP 401).
- Persistenza verificata dopo nuove pubblicazioni: SHA-256 di `/data/site.json` invariato (`8257d069ab9201377f89a119d08d20179025bfb2ad3ea27cf5150692d2ca1155`); immagine caricata dal CMS ancora disponibile e identica all'originale. Le due copie di prova sono state poi rimosse, senza modificare i contenuti del sito.
- Il servizio usa un solo volume persistente: durante la sostituzione del container e stata osservata una breve interruzione. Non si tratta di un rilascio senza downtime.
- Resta non verificata una prenotazione su Google Calendar reale: configurazione e informativa devono essere completate prima dell'attivazione. Nessun dato di pazienti e stato usato nel collaudo.

## Riferimenti

- https://developers.google.com/search/docs/fundamentals/ai-optimization-guide
- https://developers.google.com/search/docs/essentials
- https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data
- https://www.laurarossin.it/ (confronto con il sito pubblico)

Google indica che per le sue funzioni generative restano centrali le pratiche SEO di base, i contenuti utili e la struttura tecnica. Non richiede testi costruiti per singole varianti di query o un file llms.txt.
