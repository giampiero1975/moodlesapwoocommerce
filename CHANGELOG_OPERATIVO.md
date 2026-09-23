# Changelog Operativo

Storico sintetico delle modifiche operative: cosa e perche.

## 2026-09-23

- Riallineato flusso WooCommerce/PayPal da produzione.
  - Aggiunti/ripristinati `woocommerce/index.php`, `connect.php`, `email.php`, `woocommerce_helpers.php`, `WooCommerceModel.php`, `woocommerce/inc/*`.
  - Scopo: ripristinare scarico PayPal e riconciliazione con `moodle_payments`.

- Versionato `woocommerce/config_db.php`.
  - Scopo: evitare perdita/disallineamento delle configurazioni reali, non essendoci file `example`.

- Versionato `phpunit.xml`.
  - Scopo: includere tutti i file di configurazione del progetto.

- Riallineati `setInvoiceCurl.php`, `phplogger.php`, `cleanup_old_files.php` da produzione.
  - `setInvoiceCurl.php` chiama la pulizia giornaliera prima del batch.
  - `cleanup_old_files.php` pulisce log/PDF oltre 60 giorni.
  - `phplogger.php` supporta log giornaliero e log per singolo ID fattura.

- Verificata doppia chiamata su ID `1486`.
  - Log generale: due chiamate ravvicinate alle `09:42:42` e `09:42:45`.
  - Seconda chiamata bloccata correttamente da lock `IN_CORSO_*`.
  - Conclusione: doppia chiamata reale, ma lock funzionante.

- Test locale/SAP test su ID `1485`.
  - Generata fattura `2026200068`.
  - Inserito record `invoice`.
  - Aggiornato `moodle_payments.sales=1`.
  - Creato log `1485_20260923_165629.log`.
