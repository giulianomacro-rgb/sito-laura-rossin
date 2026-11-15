# Privacy e prenotazioni: stato del 15 novembre 2026

## Completato nel sito

- Informative privacy e cookie riferite a hosting Railway, API Google Calendar, cookie tecnico del CMS e assenza di analytics.
- Titolare: Dott.ssa Laura Rossin, P.IVA 03119240219, piazza Domenicani 15, 39100 Bolzano (BZ); contatti esistenti del sito.
- Nota sul motivo del colloquio facoltativa. Consenso esplicito registrato nell'evento con data e versione dell'informativa (hash del contenuto effettivo).
- Calendario dedicato: eventi con orario disponibili senza #disp. Esclusi eventi annullati, con invitati, di giornata intera, di tipo speciale, con titolo Appuntamento/Occupato o contrassegno privato di prenotazione.
- Contrassegno `lauraBooked` persistente anche se il titolo viene cambiato. ETag e blocco locale delle scritture evitano conflitti; una seconda prenotazione dello stesso evento riceve 409.
- Collaudo visibile solo con sessione CMS valida. Consenso di prova obbligatorio ed email @example.invalid. Nessun dato sanitario reale nei test.
- Nessuna pubblicazione automatica dei dettagli del calendario. Nessun invito email automatico.

## Prima di aprire a persone reali

La pagina privacy non certifica la conformita del trattamento. La titolare deve verificare e approvare:

1. Informativa, basi giuridiche, gestione dei minorenni e procedura per revoche/diritti.
2. Tempi effettivi di conservazione e cancellazione di agenda, richieste, log e copie. Il sito non cancella automaticamente gli eventi Google.
3. Adeguatezza e accordi dell'account Google utilizzato; accordi con Railway e incaricati tecnici.
4. Garanzie per trasferimenti extra SEE: il deployment attuale e in regione sfo (Stati Uniti). Non si dichiara un hosting UE inesistente.
5. Calendario non pubblico, condivisioni limitate, autenticazione forte degli account e custodia/revoca della chiave di servizio.
6. Aggiornare la sezione Stato del servizio nell'informativa; solo dopo disattivare il collaudo e confermare la verifica privacy nel CMS.

## Collaudo Google reale

Creare solo eventi fittizi nel calendario dedicato. Usare un evento senza tag, un evento Occupato sovrapposto e un evento con invitati di prova per verificare i filtri. Rimuovere gli eventi di prova al termine. Non modificare appuntamenti preesistenti.

L'ID fornito va salvato nella configurazione privata del CMS, mai in script pubblici. La chiave non va inserita in GitHub, chat, HTML o cartelle pubbliche. Il sito resta chiuso al pubblico durante il collaudo.

Test automatizzati: `tests/booking.php`, `tests/booking-flow.php` (Google simulato, non verifica credenziali o condivisioni reali), `tests/render.php`, `tests/cms.mjs`, `tests/http.mjs`.

## Riferimenti ufficiali consultati

- GDPR, artt. 6, 9, 13 e diritti: https://eur-lex.europa.eu/eli/reg/2016/679/oj/?locale=it
- Garante, chiarimenti dati sanitari: https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9091942
- Garante, cookie tecnici: https://www.garanteprivacy.it/faq/Cookie
- Railway DPA: https://railway.com/legal/dpa
- Google Calendar eventi e proprieta private: https://developers.google.com/workspace/calendar/api/v3/reference/events
- Google, chiavi account di servizio: https://docs.cloud.google.com/iam/docs/keys-create-delete
