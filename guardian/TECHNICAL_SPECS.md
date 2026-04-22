# 🛡️ SAP Guardian 3.0 - Documentazione Tecnica & Operativa

Il sistema **SAP Guardian 3.0** è un modulo di monitoraggio attivo e auto-riparazione (self-healing) progettato per garantire la continuità operativa della pipeline di sincronizzazione tra Moodle/WooCommerce e SAP Business One.

---

## 1. Architettura: Il "Parassita Buono"
Il modulo adotta un'architettura **isolata ma integrata**. Risiede interamente nella cartella `/guardian`, riducendo al minimo l'invasività nel core dell'applicazione originale, ma riutilizzando i modelli di business esistenti per la comunicazione con SAP e il database.

### Gerarchia dei File
- `/guardian/check.php`: Intercettore (Firewall) di ingresso.
- `/guardian/data/`: Layer di persistenza JSON (Telemetria e Stati).
- `/guardian/ui/dashboard.php`: Dashboard di monitoraggio visuale.
- `/guardian/tests/simulator.php`: Ambiente di simulazione per validazione logiche.

---

## 2. Il Motore di Diagnostica (Sensing)
Il sistema valuta lo stato di salute analizzando tre sensori critici in parallelo:

1.  **Web Service (IIS)**: Ping HTTP verso l'endpoint B1Sync.
2.  **Database (SQL Server)**: Verifica connettività e latenza di esecuzione query su MSSQL.
3.  **DI-Server (SOAP)**: Test di connettività profonda verso il DI-Server di SAP tramite chiamata SOAP.

### Soglie di Stato (Thresholds)
- **🟢 VERDE (Operativo)**: Latenza peggiore < 200ms. Il sistema è fluido.
- **🟡 GIALLO (Degradato)**: Latenza tra 200ms e 2000ms. Rilevata congestione SQL o IIS.
- **🔴 ROSSO (Critico)**: Latenza > 2000ms o servizio Offline. Il sistema è in blocco.

---

## 3. Logica di Intervento (Self-Healing)
Quando il sistema esce dallo stato VERDE, l'orchestratore (`SapInvoiceHandler`) esegue azioni correttive automatiche:

| Stato | Azione Tecnica | Notifica WhatsApp | Effetto sulla Pipeline |
| :--- | :--- | :--- | :--- |
| **GIALLO** | `runSqlCleanup()`: Kill dei processi SQL pendenti (>4 ore). | Alert "Lentezza" | **EXIT** (Batch posticipato) |
| **ROSSO** | `runSqlReset()`: Avvio SQL Job di ripristino IIS/SAP. | Alert "Critico" | **EXIT** (Batch posticipato) |

### Il "Firewall" in `setInvoiceCurl.php`
L'integrazione nel cronjob di fatturazione è brutale e sicura:
```php
require_once 'guardian/check.php';
if ($stats['stato'] !== 'VERDE') {
    $sapHandler->gestisciStatoServer($stats);
    exit("Stop di sicurezza");
}
```
Se il sistema non è perfetto, il Guardiano agisce e **interrompe immediatamente** l'esecuzione per evitare timeout infiniti o corruzione dati.

---

## 4. Gestione Dati e Persistenza
Per massimizzare le performance, il monitoraggio utilizza file JSON invece di sovraccaricare il database per le letture frequenti.

### Telemetria (Buffer Circolare O(1))
Il file `telemetry.json` mantiene solo gli ultimi **96 record** (circa 48 ore di dati se il cron gira ogni 30 min). 
- **Auto-Cleaning**: Ad ogni scrittura, il sistema esegue un `array_slice`, garantendo che il file non cresca mai oltre pochi KB.
- **Dashboard**: Chart.js legge questo file per disegnare il grafico in tempo reale.

### Anti-Spam (WhatsApp Cooldown)
Il file `state.json` memorizza l'ultimo allarme inviato.
- **Cooldown**: Il sistema non invia più di un alert ogni **60 minuti** per lo stesso stato, evitando il flooding di messaggi sul telefono del sistemista.

---

## 5. Strumenti Operativi

### Dashboard (`/guardian/ui/dashboard.php`)
Visualizza:
- Stato attuale dei sensori (IIS, DB, SAP).
- Grafico storico delle latenze.
- Storico degli interventi (estratto dal DB MySQL `log_ws_sap`).
- Statistiche di Uptime dei processi Windows.

### Simulatore (`/guardian/tests/simulator.php`)
Permette di testare le manovre di emergenza e l'invio degli alert WhatsApp senza mandare realmente in crash il server. Utilizza una classe *Mock* che eredita dal Guardiano reale.

---

## 6. Manutenzione e Troubleshooting
- **Log Database**: Gli interventi reali sono sempre scritti nella tabella MySQL `log_ws_sap`.
- **Fuso Orario**: Impostato su `Europe/Rome` per garantire la coerenza tra orario del server e orario italiano.
- **Permessi**: La cartella `/guardian/data/` deve avere permessi di scrittura per l'utente web (IIS/Apache).
