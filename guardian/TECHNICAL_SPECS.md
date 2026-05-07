# 🛡️ SAP Guardian 3.0 - Documentazione Tecnica & Operativa

Il sistema **SAP Guardian 3.0** è un modulo di monitoraggio attivo e auto-riparazione (self-healing) progettato per garantire la continuità operativa della pipeline di sincronizzazione tra Moodle/WooCommerce e SAP Business One.

---

## 1. Architettura: Il "Parassita Buono"
Il modulo adotta un'architettura **isolata ma integrata**. Risiede interamente nella cartella `/guardian`, riducendo al minimo l'invasività nel core dell'applicazione originale, ma riutilizzando i modelli di business esistenti per la comunicazione con SAP e il database.

### Gerarchia dei File
- `/guardian/check.php`: Intercettore (Firewall) di ingresso.
- `/guardian/data/`: Layer di persistenza JSON (Telemetria e Stati).
- `/guardian/ui/dashboard.php`: Dashboard di monitoraggio visuale.
- `/guardian/tests/logic_verification.php`: Suite di test per la validazione delle logiche di escalation.

---

## 2. Il Motore di Diagnostica (Sensing)
Il sistema valuta lo stato di salute analizzando tre sensori critici in parallelo:

1.  **Web Service (IIS)**: Ping HTTP verso l'endpoint B1Sync.
2.  **Database (SQL Server)**: Verifica connettività e latenza di esecuzione query su MSSQL.
3.  **DI-Server (SOAP)**: Test di connettività profonda verso il DI-Server di SAP tramite chiamata SOAP.

### Soglie di Stato (Thresholds) Differenziate
- **Componente WEB (IIS)**:
  - 🟢 **VERDE**: $< 150\text{ ms}$
  - 🟡 **GIALLO**: $150\text{ ms} - 500\text{ ms}$
  - 🔴 **ROSSO**: $> 500\text{ ms}$
- **Componente SOAP (DI-Server)**:
  - 🟢 **VERDE**: $< 800\text{ ms}$
  - 🟡 **GIALLO**: $800\text{ ms} - 3000\text{ ms}$
  - 🔴 **ROSSO**: $> 3000\text{ ms}$

---

## 3. Logica di Intervento ed Escalation (Self-Healing)
Quando il sistema rileva uno stato degradato o critico, l'orchestratore (`SapServiceHandler`) esegue azioni correttive automatiche basate su una logica a 3 livelli, delegando la manovra di riavvio interamente al Job SQL (coordinato tramite il file `.bat` remoto) per prevenire collisioni di processo:

| Livello | Condizione | Azione Tecnica | Notifica Telegram | Effetto sulla Pipeline |
| :--- | :--- | :--- | :--- | :--- |
| **1** | **GIALLO** (1°-4° check) | `runSqlCleanup()`: Kill dei processi SQL pendenti (>4 ore). | Alert "Lentezza" (1 ogni 60m) | **EXIT** (Batch interrotto) |
| **2** | **ESCALATION** (5° GIALLO cons.) | `runSqlReset()`: Reset coordinato (Stop/Pulizia/Start) via Job SQL. | Alert "Escalation Critica" | **EXIT** (Batch interrotto) |
| **3** | **ROSSO** (Stato Critico rilevato) | `runSqlReset()`: Reset coordinato (Stop/Pulizia/Start) via Job SQL. | Alert "Stato Critico" | **EXIT** (Batch interrotto) |

### Il "Firewall" in `setInvoiceCurl.php`
L'integrazione nel cronjob di fatturazione garantisce l'integrità dei dati:
```php
require_once 'guardian/check.php'; // Esegue il monitoraggio e le riparazioni
if ($stats['stato'] !== 'VERDE') {
    exit("🛑 GUARDIANO: Sistema instabile. Batch interrotto.\n");
}
```
Se il sistema non è nello stato ottimale, il Guardiano agisce e **interrompe immediatamente** l'esecuzione per evitare timeout infiniti o corruzione dati.

---

## 4. Gestione Dati e Persistenza Atomica
Per massimizzare le performance e l'affidabilità, il monitoraggio utilizza file JSON gestiti in modalità atomica.

### Telemetria (Storico Analitico 7 Giorni)
Il file `telemetry.json` mantiene lo storico completo degli ultimi **7 giorni**. 
- **Log Continuo**: Ogni esecuzione del cron viene registrata per garantire una visibilità granulare.
- **Auto-Cleaning**: Ad ogni scrittura, il sistema rimuove automaticamente i record più vecchi di una settimana basandosi sul timestamp.
- **Performance**: Chart.js legge questo file per visualizzare l'andamento analitico senza campionamenti.

### Anti-Spam e Stato (Persistenza Condivisa)
$file `state.json` gestisce la memoria a breve termine del sistema.
- **Gestione Atomica**: Lo stato è caricato in un buffer di classe unico per evitare conflitti tra contatori e allarmi.
- **Telegram Cooldown**: Il sistema non invia più di un alert ogni **60 minuti** per lo stesso stato di degrado.
- **Normalizzazione**: Gli stati sono standardizzati in `VERDE`, `GIALLO`, `ROSSO`.

---

## 5. Strumenti Operativi e Diagnostica

### Dashboard (`/guardian/ui/dashboard.php`)
Visualizza in tempo reale:
- Stato "semaforico" dei sensori (IIS, DB, SAP).
- **Grafico Real-Time**: Andamento complessivo delle latenze dell'intera settimana con linea di soglia visiva.
- **Grafico Zoom Giornaliero (Dettaglio Variazioni)**: Ingrandimento dinamico ad auto-scale che pre-seleziona l'ultima giornata per un esame dettagliato dei flussi e consente di saltare istantaneamente ad altri giorni storici tramite selettore integrato in JavaScript.
- Storico degli interventi reali (Tabella `log_ws_sap`).
- Uptime dei processi Windows (SAP B1 e IIS).

### Suite di Test (`/guardian/tests/logic_verification.php`)
Ambiente di simulazione per validare la logica di escalation a 3 livelli e il cooldown degli allarmi senza influenzare i servizi reali.

---

## 6. Manutenzione e Troubleshooting
- **Log Database**: Gli interventi reali sono sempre scritti nella tabella MySQL `log_ws_sap`.
- **Fuso Orario**: Impostato su `Europe/Rome` per coerenza assoluta con i log di sistema.
- **Permessi**: La cartella `/guardian/data/` deve essere scrivibile dall'utente IIS.
