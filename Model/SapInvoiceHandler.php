<?php
require_once PROJECT_ROOT_PATH . 'phplogger.php';
require_once PROJECT_ROOT_PATH . 'Model/SapModel.php';
require_once PROJECT_ROOT_PATH . 'Model/DBMoodle.php';

class SapServiceHandler {
    private $logger;
    private $sapModel;
    private $dbLocal;
    private $connectionError = false; // Flag per gestire SQL offline
    
    public function __construct() {
        $this->logger = Logger::get_logger();
        $this->dbLocal = new dbmoodle('mdlapps_moodleadmin');
        
        try {
            // Tentativo di connessione a SAP. Se fallisce (es. SQL Update), non crasha
            $this->sapModel = new SapModel();
        } catch (Exception $e) {
            $this->connectionError = true;
            $this->logger->error("Errore connessione SAP nel costruttore: " . $e->getMessage());
        }
    }
    
    public function pingWebService($maxRetries = 3) {
        $url = "http://192.168.10.44/wsToSAP/B1Sync.asmx";
        
        $ms = 0;
        $isAlive = false;
        
        // Ciclo di tentativi (massimo 3)
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // Aumentiamo leggermente il timeout a 6 secondi per dare respiro al Cold Start
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            
            $ms = round($info['total_time'] * 1000, 2);
            $isAlive = ($info['http_code'] > 0);
            
            // Se il ping è "Accettabile" (VERDE o GIALLO, quindi sotto i 2000ms),
            // fermiamo subito il ciclo. Il server è sveglio!
            if ($isAlive && $ms < 200) {
                break;
            }
            
            // Se siamo qui, il ping è fallito o è lentissimo (> 2000ms).
            // Se non è l'ultimo tentativo, aspettiamo 2 secondi e riproviamo.
            if ($attempt < $maxRetries) {
                sleep(2);
            }
        }
        
        // Calcoliamo lo stato finale in base all'esito dell'ultimo tentativo valido
        if (!$isAlive) { $stato = "⚫ OFFLINE"; }
        elseif ($ms < 200) { $stato = "🟢 VERDE"; }
        elseif ($ms <= 2000) { $stato = "🟡 GIALLO"; } // Allineato alla logica dell'orchestratore
        else { $stato = "🔴 ROSSO"; }
        
        return [
            'is_alive' => $isAlive,
            'total_time_ms' => $ms,
            'stato' => $stato,
            'tentativi' => min($attempt, $maxRetries) // Utile se vuoi loggare quanti colpi ci sono voluti
        ];
    }
    
    public function gestisciStatoServer($stats) {
        // --- ESCAPE DI EMERGENZA: Se SQL è giù, esci subito con grazia ---
        if ($this->connectionError) {
            echo "⚠️ EMERGENZA: Il server SQL non risponde (Aggiornamenti in corso?). Salto controllo.<br>";
            return false;
        }
        
        $ping = $stats['total_time_ms'];
        if (!$stats['is_alive']) { $ping = 9999; }
        
        $final_label = "";
        $ps_info = "Check non eseguito";
        
        switch (true) {
            case ($ping > 2000):
                echo "2. AZIONE: Stato CRITICO (ROSSO 🔴). Avvio Manovra Rossa...<br>";
                $id_log = $this->iniziaLog('ROSSO', $ping, 'Reset Remoto Job SQL');
                $res_job = $this->runSqlReset();
                
                // Se il reset fallisce perché il DB è diventato irraggiungibile ora
                if (strpos($res_job, 'ERRORE') !== false) {
                    $this->chiudiLog($id_log, $ping, false, "Timeout SQL", "🔴 FALLITO");
                    return false;
                }
                
                $this->aggiornaStatoJob($id_log, true);
                echo "   Reset inviato. Pausa di 3 minuti...<br>";
                sleep(180);
                
                $post_stats = $this->pingWebService();
                $ps_info = $this->getSystemUptimeCheck();
                
                $is_success = ($post_stats['is_alive'] === true && $post_stats['total_time_ms'] < 2000);
                
                if ($is_success) {
                    $final_label = ($post_stats['total_time_ms'] < 200) ? "🟢 VERDE" : "🟠 RIPRISTINATO";
                } else {
                    $final_label = "🔴 FALLITO";
                }
                
                $this->chiudiLog($id_log, $post_stats['total_time_ms'], $is_success, $ps_info, $final_label);
                return $is_success;
                
            case ($ping >= 200 && $ping <= 2000):
                echo "2. AZIONE: Latenza rilevata (GIALLO 🟡). Eseguo Pulizia...<br>";
                $id_log = $this->iniziaLog('GIALLO', $ping, 'Pulizia SQL');
                $res_cleanup = $this->runSqlCleanup();
                $this->aggiornaStatoJob($id_log, (strpos($res_cleanup, 'ERRORE') === false));
                
                $post_stats = $this->pingWebService();
                $ps_info = $this->getSystemUptimeCheck();
                $final_label = $post_stats['stato'];
                
                $this->chiudiLog($id_log, $post_stats['total_time_ms'], true, $ps_info, $final_label);
                return true;
                
            default:
                echo "2. INFO: Sistema operativo.<br>";
                return true;
        }
    }
    
    private function iniziaLog($colore, $ping, $azione) {
        $sql = "INSERT INTO log_ws_sap (ping_result, ping_delay, action, result) VALUES (?, ?, ?, 'IN CORSO')";
        $this->dbLocal->insCrm($sql, [$colore, $ping, $azione]);
        $res = $this->dbLocal->select("SELECT MAX(id) as last_id FROM log_ws_sap");
        return $res[0]['last_id'] ?? 0;
    }
    
    private function aggiornaStatoJob($id, $successo) {
        $stato = $successo ? 'SENT' : 'ERROR';
        $this->dbLocal->insCrm("UPDATE log_ws_sap SET job_sql_status = ? WHERE id = ?", [$stato, $id]);
    }
    
    public function chiudiLog($id_log, $post_ping, $success, $ps_status = null, $post_result = null) {
        $status = $success ? 'COMPLETATO' : 'FALLITO';
        $sql = "UPDATE log_ws_sap SET post_ping_delay = ?, post_ps_check = ?, post_ping_result = ?, result = ? WHERE id = ?";
        $this->dbLocal->insCrm($sql, [
            (float)$post_ping,
            (string)$ps_status,
            (string)$post_result,
            $status,
            (int)$id_log
        ]);
    }
    
    public function runSqlCleanup() {
        $sql = "DECLARE @sql_kill NVARCHAR(MAX) = ''; SELECT @sql_kill += 'KILL ' + CAST(spid AS VARCHAR(10)) + '; ' FROM master.dbo.sysprocesses WHERE (program_name LIKE '%SBODI%' OR program_name LIKE '%Information Services%') AND last_batch <= DATEADD(HOUR, -4, GETDATE()) AND spid > 50; IF @sql_kill <> '' BEGIN EXEC sp_executesql @sql_kill; SELECT 'SUCCESSO' as esito; END ELSE SELECT 'ZERO' as esito;";
        try {
            if(!$this->sapModel) return "ERRORE: Modello SQL non inizializzato";
            $res = $this->sapModel->select($sql);
            return $res[0]['esito'] ?? 'Eseguito';
        } catch (Exception $e) { return "ERRORE: " . $e->getMessage(); }
    }
    
    public function runSqlReset() {
        try {
            if(!$this->sapModel) return "ERRORE: Modello SQL non inizializzato";
            $this->sapModel->updCrm("EXEC msdb.dbo.sp_start_job @job_name = 'Reset_SAP_Emergenza'");
            return "Segnale inviato";
        } catch (Exception $e) { return "ERRORE: " . $e->getMessage(); }
    }
    
    public function getSystemUptimeCheck() {
        // Usiamo il ponte SQL per leggere processi e CreationDate tramite WMI
        $sql = "EXEC xp_cmdshell 'powershell -NoProfile -Command \"Get-CimInstance Win32_Process | Where-Object { \$_.Name -match ''B1_DIServer|w3wp'' } | Select-Object Name, CreationDate | Format-Table -HideTableHeaders\"'";
        
        try {
            if(!$this->sapModel) return "Modello SQL non connesso";
            
            $res = $this->sapModel->select($sql);
            
            $output = "";
            if (is_array($res)) {
                foreach($res as $row) {
                    if (!empty(trim($row['output'] ?? ''))) {
                        $output .= trim($row['output']) . " | ";
                    }
                }
            }
            
            // Puliamo l'ultimo separatore " | "
            $output = rtrim($output, " | ");
            
            $hasSap = (stripos($output, 'B1_DIServer') !== false);
            $hasIis = (stripos($output, 'w3wp') !== false);
            
            // Formattazione per il database (campo post_ps_check)
            if ($hasSap && $hasIis) {
                // Se sono su, salviamo UP e il dettaglio degli orari
                return "Stato UP - Dettaglio: " . $output;
            }
            
            if (empty(trim($output))) {
                return "Nessun processo trovato";
            }
            
            // Se ne manca uno, salviamo lo stato parziale e il dettaglio di chi c'è
            return ($hasSap ? "[SAP OK]" : "[SAP OFF]") . " " . ($hasIis ? "[IIS OK]" : "[IIS OFF]") . " - Dettaglio: " . $output;
            
        } catch (Exception $e) {
            return "Errore SQL Bridge: " . $e->getMessage();
        }
    }
}