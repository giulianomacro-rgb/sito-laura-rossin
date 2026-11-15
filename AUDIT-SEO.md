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
- Test HTTP su copia locale isolata: 14 percorsi pubblici, errori 404, prenotazioni disattivate, login/logout CMS, protezione CSRF, salvataggio e rilettura degli stessi contenuti, upload JPEG e rifiuto SVG.
- Non eseguiti: collaudo visivo desktop/mobile e login/salvataggio del CMS remoto, per mancata autorizzazione del browser. Non verificata una prenotazione su Google Calendar reale.

## Riferimenti

- https://developers.google.com/search/docs/fundamentals/ai-optimization-guide
- https://developers.google.com/search/docs/essentials
- https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data
- https://www.laurarossin.it/ (confronto con il sito pubblico)

Google indica che per le sue funzioni generative restano centrali le pratiche SEO di base, i contenuti utili e la struttura tecnica. Non richiede testi costruiti per singole varianti di query o un file llms.txt.
