# Documentazione Tecnica: Script Sincronizzazione PayPal-WooCommerce-Moodle

## 1. Panoramica del Sistema

Il software è un'applicazione backend progettata per automatizzare l'iscrizione degli utenti ai corsi **Moodle** a seguito di un acquisto effettuato su piattaforme **WooCommerce**, utilizzando **PayPal** come gateway di pagamento. Lo script funge da "ponte" recuperando le transazioni e simulando il flusso di inserimento dati richiesto dal sistema `moodlesap`.

## 2. Architettura dei File

* **`index.php`**: Il punto di ingresso (Core). Gestisce la logica di controllo, l'interazione con l'API di PayPal e lo smistamento delle transazioni.
* **`config_db.php`**: File di configurazione centralizzato. Contiene credenziali DB, parametri API PayPal, mappature tra istanze e modalità operativa (`TEST` vs `PRODUCTION`).
* **`woocommerce_helpers.php`**: Contiene la logica specifica per interrogare i database di WordPress (HPOS) e Moodle. Gestisce l'estrazione del Codice Fiscale e la ricerca degli ID corso/utente.
* **`connect.php`**: Gestore delle connessioni DB (Pattern Singleton). Centralizza l'accesso a database multipli (WP, Moodle, MoodleApps).
* **`email.php`**: Gestisce l'invio di notifiche via email in caso di nuovi pagamenti generici.
* **`logger_init.php`**: Inizializza il logging dettagliato tramite la libreria Monolog.

## 3. Flusso Logico Operativo

### Step 1: Recupero Transazioni

Lo script interroga l'API PayPal per ottenere transazioni con stato `S` (Success) in un intervallo temporale definito dalle variabili `$startDate` e `$endDate` in `index.php`.

### Step 2: Identificazione Istanza (Routing)

Il sistema analizza il campo `invoice_id` ricevuto da PayPal:

* Se l'ID inizia con un prefisso definito in `WC_INSTANCE_MAPPING` (es. `MeiOSS-`), la transazione è trattata come **Ordine WooCommerce**.
* Se il prefisso non corrisponde, la transazione è considerata un **Pagamento Generico** e salvata nella tabella `results`.

### Step 3: Elaborazione WooCommerce (Logica Helper)

Per gli ordini identificati:

1. **Connessione DB WP**: Accede direttamente al database dell'istanza WordPress corretta.
2. **Estrazione Dati**: Recupera il Codice Fiscale (`billing_cf`) dai metadati dell'ordine.
3. **Mappatura Prodotto-Corso**: Cerca nel database WP l'ID del corso Moodle associato al prodotto acquistato tramite il meta `moodle_course_id`.
4. **Ricerca Utente Moodle**: Cerca l'ID utente nel database Moodle che corrisponde al Codice Fiscale estratto.

### Step 4: Finalizzazione

* **In Produzione**: Se i controlli hanno esito positivo, viene inserito un record nella tabella `moodle_payments` del database `MoodleApps`.
* **In Test**: I dati vengono accodati in un file CSV definito da `TEST_OUTPUT_FILE`.

## 4. Analisi Critica e Punti di Debolezza

Dall'analisi del codice emergono i seguenti rischi tecnici:

* **Date Statiche (Hardcoded)**: In `index.php`, le date di inizio e fine ricerca sono fissate manualmente (es. Aprile 2025). Questo impedisce il recupero di transazioni recenti senza modifiche al codice.
* **Dipendenza CF**: La sincronizzazione fallisce se il Codice Fiscale non è presente su Moodle o se è scritto con formattazioni incoerenti (spazi, caratteri speciali).
* **Connessioni IP**: L'uso di IP locali statici (es. `192.168.11.16`) rende il software vulnerabile a cambiamenti nell'infrastruttura di rete.
* **Mapping Rigido**: L'aggiunta di nuove istanze richiede l'aggiornamento manuale dell'array `WC_INSTANCE_MAPPING` in `config_db.php`.

## 5. Manutenzione Rapida

* **Cambiare date di scansione**: Modificare `$startDate` e `$endDate` in `index.php`.
* **Passare in Produzione**: Impostare `APP_MODE` su `'PRODUCTION'` in `config_db.php`.
* **Controllare errori**: Verificare i file nella cartella `/logs/` per identificare fallimenti di connessione o transazioni saltate.