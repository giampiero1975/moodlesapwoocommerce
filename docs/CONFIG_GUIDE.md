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
