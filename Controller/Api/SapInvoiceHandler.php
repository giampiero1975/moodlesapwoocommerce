<?php
// 1. Importiamo il file di configurazione base (che di solito imposta le costanti come PROJECT_ROOT_PATH)
require_once __DIR__ . '/../../inc/bootstrap.php';

// 2. Importiamo le classi che il nostro costruttore cerca di istanziare
require_once PROJECT_ROOT_PATH . 'phplogger.php';
require_once PROJECT_ROOT_PATH . 'Model/SapModel.php';
require_once PROJECT_ROOT_PATH . 'Model/DBMoodle.php';

/**
 * Handler per la gestione del ciclo SAP.
 * Gestisce controlli preventivi, invio, polling di recupero e salvataggio in coda.
 */
class SapServiceHandler {
    private $logger;
    private $sapModel;
    private $dbLocal; // Connessione per salvare nella tabella di appoggio
    
    // Proprietà di stato
    private $lastDocEntry = null;
    private $lastError = null;
    
    public function __construct() {
        $this->logger = Logger::get_logger();
        $this->sapModel = new SapModel();
        $this->dbLocal = new dbmoodle('mdlapps_moodleadmin'); // Usiamo il tuo DB locale
    }
    
    // ==========================================
    // PUNTO 0: CHECK PREVENTIVO (PING)
    // ==========================================
    /**
     * Verifica velocemente se il server SAP è raggiungibile prima di inviare dati pesanti.
     * @return bool True se risponde, False se è spento o irraggiungibile.
     */
    // ==========================================
    // PUNTO 0: CHECK PREVENTIVO (PING) POTENZIATO
    // ==========================================
    /**
     * Verifica se il server SAP è raggiungibile e raccoglie statistiche di rete.
     * @return array Contiene lo stato, i tempi di risposta e i codici HTTP.
     */
    public function pingWebService() {
        $url = "http://192.168.10.44/wsToSAP/B1Sync.asmx";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        // RIMOSSO: curl_setopt($ch, CURLOPT_NOBODY, true);
        // AGGIUNTO: Forziamo cURL a chiudere davvero la connessione TCP dopo l'uso (spiego sotto il perché)
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        
        curl_exec($ch);
        
        // Estraiamo TUTTE le statistiche da cURL
        $info = curl_getinfo($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Se l'http_code è maggiore di 0 significa che il server ha risposto
        $isAlive = ($info['http_code'] > 0);
        
        // Creiamo il nostro array con le informazioni utili convertite in millisecondi
        $stats = [
            'is_alive'        => $isAlive,
            'http_code'       => $info['http_code'],
            'total_time_ms'   => round($info['total_time'] * 1000, 2), // Tempo totale per completare l'operazione
            'connect_time_ms' => round($info['connect_time'] * 1000, 2), // Tempo per stabilire la connessione
            'error_msg'       => $curlError
        ];
        
        if (!$isAlive) {
            $this->logger->log("PING FALLITO: Il server $url non è raggiungibile. Dettagli: " . json_encode($stats));
        }
        
        return $stats; // Restituiamo tutto il pacchetto di informazioni!
    }
    
    // ==========================================
    // GETTER: IL CHECK SUL DB SAP
    // ==========================================
    public function getSapCheck($docNum) {
        $res = $this->sapModel->checkInvoice($docNum);
        if ($res) {
            $this->lastDocEntry = (string)$res;
            return $this->lastDocEntry;
        }
        return false;
    }
    
    // ==========================================
    // SETTER: AZIONE PRINCIPALE DI INVIO
    // ==========================================
    public function executeInvoiceCycle($xml, $docNum) {
        $this->lastDocEntry = null;
        $this->lastError = null;
        
        // PUNTO 0: Controllo preventivo
        if (!$this->pingWebService()) {
            $this->lastError = "Server SAP irraggiungibile.";
            $this->saveToRecoveryQueue($docNum, $xml, "offline_server");
            return false;
        }
        
        // L'invio vero e proprio (La classe sendXml dovrà essere aggiornata per restituire 'timeout')
        $ws = new sendXml($xml);
        $res = $ws->sendSoap('invoice', $docNum);
        
        // Successo Immediato
        if ($res && is_numeric((string)$res)) {
            $this->lastDocEntry = (string)$res;
            return true;
        }
        
        // PUNTO 2: Intercettiamo un Timeout / Nessuna risposta e attiviamo il Polling
        if ($res === 'timeout' || $res === 'check' || $res === false) {
            $this->logger->log("Problema di comunicazione col WS. Avvio polling per $docNum...");
            
            // Attese crescenti per dare respiro a SAP (10s, 20s, 30s)
            $ritardi = [10, 20, 30];
            
            foreach ($ritardi as $secondi) {
                sleep($secondi);
                $checkResult = $this->getSapCheck($docNum);
                
                if ($checkResult) {
                    $this->logger->log("Fattura recuperata dopo timeout! DocEntry: $checkResult");
                    return true; // Vittoria!
                }
            }
            
            // PUNTO 3: Fallimento definitivo. Salviamo in tabella di appoggio.
            $this->lastError = "Timeout server e polling fallito.";
            $this->saveToRecoveryQueue($docNum, $xml, "timeout_and_not_found");
            return false;
        }
        
        // Errore logico (es. dati XML sbagliati)
        $this->lastError = "Errore logico restituito da SAP.";
        $this->saveToRecoveryQueue($docNum, $xml, "sap_logic_error");
        return false;
    }
    
    // ==========================================
    // PUNTO 3: SALVATAGGIO IN TABELLA APPOGGIO
    // ==========================================
    /**
     * Salva l'XML fallito per un successivo re-invio manuale.
     */
    private function saveToRecoveryQueue($docNum, $xml, $reason) {
        $this->logger->log("Salvataggio documento $docNum nella tabella di appoggio...");
        
        // Pulizia stringhe per SQL
        $cleanXml = addslashes($xml);
        $cleanReason = addslashes($reason);
        $dataOggi = date('Y-m-d H:i:s');
        
        // Esempio di Query (dovrai creare questa tabella nel tuo DB Moodle locale)
        $sql = "INSERT INTO sap_recovery_queue (docnum, xml_data, fail_reason, created_at, status)
                VALUES ('$docNum', '$cleanXml', '$cleanReason', '$dataOggi', 'pending')";
        
        // Esegui la query usando il tuo modello
        $this->dbLocal->create($sql);
    }
    
    // --- GETTER GENERICI ---
    public function getLastDocEntry() { return $this->lastDocEntry; }
    public function getLastError() { return $this->lastError; }
}

$ping = new SapServiceHandler();
echo "<pre>Ping: ".print_r($ping->pingWebService())."\n";