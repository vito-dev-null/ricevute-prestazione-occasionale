# Ricevute per prestazione occasionale

Applicazione PHP locale per gestire le anagrafiche di prestatori e committenti, registrare e numerare le ricevute, consultare l'archivio, esportare i dati in CSV e stampare una ricevuta o salvarla in PDF dal browser. Il logo fornito è incluso nella ricevuta e nell'interfaccia.

## Sicurezza e distribuzione

Questa è un'applicazione desktop locale: non offre account o autenticazione per accessi remoti. L'app accetta solo indirizzi e nomi host locali e rifiuta le richieste con intestazioni tipiche dei proxy, ma non va comunque installata su hosting pubblico né esposta tramite reverse proxy o tunnel. Usa solo il server locale indicato qui sotto. Ogni utilizzatore conserva i propri dati sul proprio computer; non vengono caricati al creatore dell'applicazione.

Il codice è distribuito secondo i termini della licenza personale inclusa in `LICENSE`: l'utilizzo personale è consentito, mentre modifica e ridistribuzione richiedono un'autorizzazione scritta. La licenza del codice non concede automaticamente diritti sul logo e sugli altri marchi. Prima di riutilizzare o distribuire il logo, assicurati di avere i relativi diritti.

Prima di pubblicare il progetto, scegli una licenza e verifica di poter distribuire il logo incluso. Il file `.gitignore` esclude database, backup e file d'ambiente locali dal controllo versione.

## Avvio

Richiede PHP 8.1 o successivo con PDO SQLite abilitato. Dalla cartella dell'applicazione avvia il server **solo in locale**:

```sh
php -S 127.0.0.1:8080
```

Apri <http://127.0.0.1:8080> nel browser. Non cambiare l'indirizzo in `0.0.0.0`: l'app non ha autenticazione ed è progettata per uso sul computer locale.

## Dati e impostazioni

Il database SQLite viene salvato fuori dalla cartella pubblica del progetto in `~/.local/share/ricevute-prestazione/ricevute.sqlite`. Le anagrafiche, il progressivo annuale e le impostazioni non vengono caricati su servizi esterni. Mantieni una copia di backup del database in un posto sicuro: contiene dati personali e fiscali.

Aliquote, soglie, bollo e ripartizione INPS sono modificabili da **Impostazioni**. Ogni ricevuta conserva una copia dei parametri usati al momento della registrazione; le modifiche successive non cambiano i documenti già archiviati. La funzione **Stampa / Salva in PDF** apre la stampa del browser: seleziona la destinazione PDF.

## Nota importante

I valori iniziali sono configurazioni di esempio, non un parere o una garanzia di correttezza fiscale. Regole, aliquote, soglie e obblighi possono dipendere dalla posizione del prestatore, del committente e dalla normativa applicabile. Verifica i dati con un professionista prima di emettere o utilizzare ricevute.
