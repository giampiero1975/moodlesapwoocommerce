# Guida Configurazioni

Promemoria operativo per evitare ricerche a tentativi quando si passa tra locale, test e produzione.

## File principali

### `inc/config.php`

Config principale del flusso fatture Moodle/SAP.

- `URL`: host applicativo usato nei link e nei rilanci.
- `REQUESTDB`: database SAP target.
  - test: `METMI_TEST`
  - produzione: `METMI_LIVE`
- `DEST_PDF`: cartella di output PDF fatture.
- `EMAIL_*`: destinatari sistema/SAP/Moodle.
- credenziali WooCommerce per le istanze gestite dal controller.

### `woocommerce/config_db.php`

Config del flusso PayPal/WooCommerce/riconciliazione.

- `APP_MODE`: se `PRODUCTION`, lo scarico scrive su `moodle_payments` / `results`; se `TEST`, usa CSV di test dove previsto.
- `ENABLE_EMAIL_NOTIFICATIONS`: abilita/disabilita notifiche email dello scarico WooCommerce.
- `WP_DB_*`: connessione ai DB WordPress/WooCommerce.
- `MOODLE_DB_*`: connessione ai DB Moodle specifici, usati per letture utenti/corsi.
- `DB_*_MDLAPPS`: connessione al DB centrale `mdlapps_moodleadmin`, dove stanno `moodle_payments`, `results`, `invoice`.
- `WC_INSTANCE_MAPPING`: mappa prefissi PayPal/WooCommerce verso DB/prefix Moodle.
- `PAYPAL_ENVIRONMENT`, `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`: accesso API PayPal.
- `SMTP_*`: invio notifiche.

## Regola pratica test sicuro

Per testare scarico PayPal reale senza scrivere sui DB produzione:

- PayPal puo restare `PRODUCTION`, per leggere transazioni reali.
- `WP_DB_*` e `MOODLE_DB_*` possono puntare a produzione se servono solo letture.
- `DB_*_MDLAPPS` deve puntare al DB locale/test, perche li avvengono le scritture su `moodle_payments`, `results`, `invoice`.
- `ENABLE_EMAIL_NOTIFICATIONS` va messo a `false` se non si vogliono notifiche operative.

## Casistiche operative frequenti

### Aggiungere una nuova istanza WooCommerce

File da verificare/modificare:

- `woocommerce/config_db.php`
- `inc/config.php`
- eventuali credenziali WooCommerce/API usate da `Model/WooCommerceModel.php`

Passaggi:

1. Aggiungere una voce in `WC_INSTANCE_MAPPING`.
2. Definire il prefisso PayPal/WooCommerce, esempio `{MeiOSS}`.
3. Impostare:
   - `wc_db_name`
   - `wc_db_prefix`
   - `moodle_db_name`
   - `cf_meta_key`
4. Verificare che il prefisso combaci con `invoice_id` PayPal.
5. Verificare che lo SKU/prodotto WooCommerce corrisponda all'articolo SAP.
6. Eseguire un test da riconciliazione prima del batch automatico.

Punti da controllare nei log:

- `PayPal: Rilevato Ordine WC`
- `DEBUG MATCH REGEX`
- `Ordine ... accodato OK`
- presenza riga in `moodle_payments`.

### Cambio DB, DNS o host

File da verificare:

- `inc/config.php`
- `woocommerce/config_db.php`
- `setInvoiceCurl.php`
- eventuali riferimenti hardcoded cercando nel repo con:

```bash
rg "vecchio-dns|vecchio-host|METMI_LIVE|METMI_TEST|db\.|moodlesap"
```

Controlli principali:

- `inc/config.php`
  - `URL`
  - `REQUESTDB`
- `woocommerce/config_db.php`
  - `WP_DB_HOST`
  - `MOODLE_DB_HOST`
  - `DB_HOST_MDLAPPS`
  - `PAYPAL_ENVIRONMENT`
- `setInvoiceCurl.php`
  - URL chiamata `/index.php/sap/ins?id=...`

Regola importante:

- `WP_DB_*` legge WooCommerce.
- `MOODLE_DB_*` legge Moodle specifici.
- `DB_*_MDLAPPS` legge/scrive `moodle_payments`, `results`, `invoice`.
- `REQUESTDB` decide il DB SAP usato negli XML.

### Regole scarico PayPal/WooCommerce

Entry point:

- `woocommerce/index.php`

Flusso:

1. Legge transazioni PayPal via API.
2. Estrae `invoice_id`.
3. Se il prefisso combacia con `WC_INSTANCE_MAPPING`, tratta il pagamento come WooCommerce.
4. Legge ordine da DB WordPress/WooCommerce.
5. Legge eventuali dati Moodle necessari.
6. Accoda in `moodle_payments`.
7. Se non riconosce la transazione come WooCommerce, salva in `results`.

Dove scrive:

- `moodle_payments`: pagamenti WooCommerce riconosciuti.
- `results`: pagamenti PayPal non riconosciuti come WooCommerce.

Dove NON dovrebbe scrivere:

- DB WordPress/WooCommerce.
- DB Moodle specifici `mdl_*`.

Campi importanti:

- `transaction_id`: ID transazione PayPal.
- `payment_id`: ID ordine WooCommerce.
- `method`: `woocommerce` o `manual`.
- `sales`: `0` da evadere, `1` evasa.
- `logfile`: log associato oppure lock/errore.

### Regole batch fatture

Entry point:

- `setInvoiceCurl.php`

Flusso:

1. Esegue guardian.
2. Esegue pulizia giornaliera file vecchi.
3. Cerca in `moodle_payments` record con `sales='0'` e `logfile IS NULL`.
4. Imposta `logfile = PRENOTATO_YYYYMMDD_HHMMSS`.
5. Chiama `/index.php/sap/ins?id=...` per ogni record.
6. Attende 120 secondi tra una chiamata e l'altra.

### Regole chiamata singola fattura

Entry point:

- `/index.php/sap/ins?id=ID`
- controller: `Controller/Api/UserController.php`

Protezioni attese:

- lock con `IN_CORSO_YYYYMMDD_HHMMSS`
- controllo `invoice` gia presente
- insert idempotente in `invoice`
- update finale di `moodle_payments.sales=1`
- log specifico `ID_YYYYMMDD_HHMMSS.log`

Se arriva una seconda chiamata ravvicinata:

- deve fermarsi con stato `IN_CORSO_*`.
- non deve generare seconda fattura.

### Log da controllare

- `logs/YYYYMMDD.log`
  - ingressi URL e chiamate ricevute.
- `logs/ID_YYYYMMDD_HHMMSS.log`
  - flusso completo della singola fattura.
- `woocommerce/logs/paypal_cron-YYYY-MM-DD.log`
  - scarico PayPal/WooCommerce.
- `logs/cleanup_YYYYMMDD.done`
  - pulizia giornaliera eseguita.

### Checklist prima di un rilascio

1. Verificare `git status`.
2. Verificare che i config reali siano inclusi.
3. Cercare riferimenti a host vecchi o non voluti.
4. Controllare `REQUESTDB`.
5. Controllare `DB_*_MDLAPPS`.
6. Controllare URL in `setInvoiceCurl.php`.
7. Eseguire almeno `php -l` sui file PHP modificati.
8. Annotare la modifica in `CHANGELOG_OPERATIVO.md`.

## Batch fatture

### `setInvoiceCurl.php`

- Lancia il guardian.
- Esegue `cleanup_old_files.php` una volta al giorno prima del batch.
- Prenota i pagamenti con `PRENOTATO_YYYYMMDD_HHMMSS`.
- Chiama `/index.php/sap/ins?id=...`.

### `cleanup_old_files.php`

- Pulisce `logs/*.log` e `fatture/*.pdf`.
- Retention predefinita: 60 giorni.
- Crea marker giornaliero `logs/cleanup_YYYYMMDD.done`.

### `phplogger.php`

- Crea log giornaliero `logs/YYYYMMDD.log`.
- Con `useLogFile()` crea log specifico fattura `logs/ID_YYYYMMDD_HHMMSS.log`.

## Regola Git

In questo progetto i file di configurazione reali vanno versionati. Non lasciare fuori config, salvo richiesta esplicita.
