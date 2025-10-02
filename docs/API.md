# Documentazione API per l'Integrazione

Questo documento riassume le interfacce di programmazione (API) disponibili per i principali applicativi coinvolti nel flusso di fatturazione: WooCommerce, Moodle e SAP.

---

## 1. WooCommerce: API REST

Fonte primaria dei dati relativi a ordini, clienti e prodotti. L'interazione avviene tramite un'API REST standard.

* **Tipo di API:** REST
* **Formato Dati:** JSON
* **Endpoint Principale:** `https://[indirizzo_tuo_sito]/wp-json/wc/v3/`
* **Autenticazione:** Chiavi API (Consumer Key & Consumer Secret) generate dal pannello di amministrazione di WooCommerce.

### Funzionalità Principali (Endpoint)

* **Ordini (`/orders`):**
    * `GET /orders`: Recupera una lista di ordini, filtrabile per stato, data, ecc.
    * `GET /orders/<id>`: **Endpoint fondamentale.** Recupera tutti i dettagli di un singolo ordine, inclusi dati di fatturazione, prodotti (`line_items`) e meta-dati personalizzati (es. Codice Fiscale).

* **Prodotti (`/products`):**
    * `GET /products/<id>`: Recupera i dettagli di un prodotto, utile per ottenere SKU o altri meta-dati.

* **Clienti (`/customers`):**
    * `GET /customers/<id>`: Recupera i dati anagrafici di un cliente specifico.

### Documentazione Ufficiale

* [WooCommerce REST API Documentation](https://woocommerce.github.io/woocommerce-rest-api-docs/)

---

## 2. Moodle: API REST (Web Services)

Interfaccia standard per interagire con la piattaforma Moodle. Richiede una configurazione preliminare dall'amministrazione del sito per essere attivata.

* **Tipo di API:** REST
* **Formato Dati:** JSON (raccomandato) o XML
* **Endpoint Principale:** `https://[indirizzo_tuo_moodle]/webservice/rest/server.php`
* **Autenticazione:** Token (`wstoken`) associato a un utente e a un servizio pre-configurato.

### Funzionalità Principali (Funzioni)

Ogni operazione è una funzione specificata nel parametro `wsfunction`.

* **Utenti (`core_user_...`):**
    * `core_user_get_users`: Permette di cercare utenti in base a criteri come `email`, `username`, o `id`.
    * `core_user_create_users`: Per creare nuovi utenti.

* **Corsi (`core_course_...`):**
    * `core_course_get_courses_by_field`: Cerca un corso in base a un campo specifico, come `idnumber` (spesso corrispondente allo SKU).

* **Iscrizioni (`core_enrol_...` o `enrol_manual_...`):**
    * `core_enrol_get_users_courses`: Restituisce la lista di corsi a cui un determinato utente è iscritto.
    * `enrol_manual_enrol_users`: Iscrive programmaticamente un utente a un corso.

### Documentazione Ufficiale

* La documentazione completa e specifica per la tua versione è disponibile direttamente all'interno della tua installazione Moodle, al percorso:
    `Amministrazione del sito > Plugin > Web service > Documentazione API`

---

## 3. SAP: Web Service SOAP (Custom)

L'interfaccia verso SAP è un Web Service privato di tipo SOAP. La comunicazione avviene inviando messaggi XML strutturati secondo specifiche custom.

* **Tipo di API:** SOAP
* **Formato Dati:** XML
* **Endpoint Principale:** Un URL specifico a cui vengono inviate le richieste SOAP (definito internamente all'applicativo `moodlesap`).

### Funzionalità Principali (Azioni XML)

Le operazioni sono definite dalla struttura del body XML della richiesta SOAP.

1.  **Creazione/Aggiornamento Anagrafica Cliente:**
    * **Azione:** Invio di un XML con root `<BusinessPartner>`.
    * **Contenuto:** Dati anagrafici, fiscali (P.IVA, CF), indirizzi e contatti del cliente.
    * **Logica di Riferimento:** `createXMLBP()` in `UserController.php`.

2.  **Creazione Fattura e Incasso:**
    * **Azione (Fattura):** Invio di un XML con `objType` impostato a `documenti`. L'XML contiene i dati della testata (`<Documents>`) e le righe del documento (`<Document_Lines>`).
    * **Logica di Riferimento (Fattura):** `createXMLInv()` in `UserController.php`.
    * **Azione (Incasso):** Invio di un XML con `objType` impostato a `primenote`. L'XML descrive il pagamento in entrata e lo collega alla fattura precedentemente creata.
    * **Logica di Riferimento (Incasso):** `incasso()` in `UserController.php`.

### Documentazione

* Non è disponibile una documentazione pubblica. La documentazione di riferimento è costituita dall'analisi del codice sorgente dell'applicativo `moodlesap`, in particolare i file e metodi sopra citati che costruiscono i messaggi XML.