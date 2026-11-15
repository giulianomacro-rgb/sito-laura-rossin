# Privacy e prenotazioni: stato del 15 novembre 2026

## Completato nel sito

- Informative privacy e cookie riferite a hosting Railway, API Google Calendar, cookie tecnico del CMS e assenza di analytics.
- Titolare: Dott.ssa Laura Rossin, P.IVA 03119240219, piazza Domenicani 15, 39100 Bolzano (BZ); contatti esistenti del sito.
- Nota sul motivo del colloquio facoltativa. Consenso esplicito registrato nell'evento con data e versione dell'informativa (hash del contenuto effettivo).
- Calendario dedicato: eventi singoli con orario disponibili senza #disp. Esclusi eventi privati, ricorrenti, annullati, con invitati, di giornata intera, di tipo speciale, con titolo Appuntamento/Occupato o contrassegno privato di prenotazione.
- Accesso Google richiesto: writer (Apporta modifiche e visualizza i dettagli degli eventi), non writerWithoutPrivateAccess. Letture e prenotazioni si interrompono se Google non restituisce un ruolo adeguato. Il CMS verifica i permessi prima di attivare il servizio e offre una verifica senza scritture.
- Le ricorrenze sono escluse per evitare che rendere privata una singola occorrenza modifichi la visibilita delle altre. Usare un evento distinto per ogni slot.
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

Completato il 15 novembre 2026 sul sito Railway con il calendario dedicato e dati inventati:

- Verifica permessi Google dal CMS riuscita.
- Conferma dal modulo web: stesso evento aggiornato, reso privato, consenso e versione informativa registrati; orario rimosso dalle disponibilita.
- Due sessioni indipendenti sullo stesso evento: una sola conferma (200), seconda richiesta respinta (409). Anche il reinvio di una prenotazione gia confermata e stato respinto.
- Eventi privati e Occupato esclusi; una disponibilita sovrapposta a Occupato non viene proposta.
- Consenso obbligatorio e protezione della sessione verificati. Risposta pubblica senza dettagli degli eventi.
- Tutti e cinque gli eventi fittizi sono stati eliminati con verifica successiva. Nessun appuntamento preesistente modificato e nessun invito inviato.
- Il collaudo rimane accessibile solo a chi ha effettuato accesso al CMS; le prenotazioni pubbliche restano chiuse e la verifica privacy per apertura pubblica non e confermata.

Per altre prove usare solo eventi fittizi, singoli e con orario. Rimuoverli al termine. Non modificare appuntamenti preesistenti.

L'ID fornito va salvato nella configurazione privata del CMS, mai in script pubblici. La chiave non va inserita in GitHub, chat, HTML o cartelle pubbliche. Il sito resta chiuso al pubblico durante il collaudo.

Test automatizzati: `tests/booking.php`, `tests/booking-flow.php` (Google simulato, non verifica credenziali o condivisioni reali), `tests/runtime.php` (salvataggio configurazione, da eseguire anche con OPcache attivo), `tests/render.php`, `tests/cms.mjs`, `tests/http.mjs`.

## Riferimenti ufficiali consultati

- GDPR, artt. 6, 9, 13 e diritti: https://eur-lex.europa.eu/eli/reg/2016/679/oj/?locale=it
- Garante, chiarimenti dati sanitari: https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9091942
- Garante, cookie tecnici: https://www.garanteprivacy.it/faq/Cookie
- Railway DPA: https://railway.com/legal/dpa
- Google Calendar eventi e proprieta private: https://developers.google.com/workspace/calendar/api/v3/reference/events
- Google, chiavi account di servizio: https://docs.cloud.google.com/iam/docs/keys-create-delete
