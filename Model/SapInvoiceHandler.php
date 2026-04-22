<?php
require_once PROJECT_ROOT_PATH . 'phplogger.php';
require_once PROJECT_ROOT_PATH . 'Model/SapModel.php';
require_once PROJECT_ROOT_PATH . 'Model/DBMoodle.php';
require_once PROJECT_ROOT_PATH . 'inc/config.php';

class SapServiceHandler {
    protected $logger;
    protected $sapModel;
    protected $dbLocal;
    protected $connectionError = false; // Flag per gestire SQL offline
    
    public function __construct() {
        $this->logger = Logger::get_logger();
        $this->dbLocal = new dbmoodle('mdlapps_moodleadmin');
        
        try {
            // Tentativo di connessione a SAP. Se fallisce (es. SQL Update), non crasha
            $this->sapModel = new SapModel();
        } catch (Exception $e) {
            $this->connectionError = true;
            $this->logger->log("Errore connessione SAP nel costruttore: " . $e->getMessage());
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
            $curlData = $this->executeCurl($ch);
            $info = $curlData['info'];
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
            'tentativi' => min($attempt, $maxRetries) 
        ];
    }
    
    public function pingDatabase() {
        $start = microtime(true);
        $is_alive = false;
        $error = null;
        $query_time = 0;
        $connection_time = 0;
        $deep_test = false;

        try {
            $cStart = microtime(true);
            // Se non è già settato (es. nei test), lo istanziamo
            if (!$this->sapModel) {
                $this->sapModel = new SapModel();
            }
            $connection_time = round((microtime(true) - $cStart) * 1000, 2);
            $is_alive = true;

            // Test 1: Latenza base
            $qStart = microtime(true);
            $this->sapModel->select("SELECT 1 as test");
            $query_time = round((microtime(true) - $qStart) * 1000, 2);
            
            // Test 2: Diagnosi Profonda on OINV (Richiesta utente)
            try {
                $this->sapModel->select("SELECT TOP 1 DocNum FROM OINV");
                $deep_test = true;
            } catch (Exception $e) {
                $deep_test = false;
                $error = "Deep Test Failed: " . $e->getMessage();
            }

        } catch (Exception $e) {
            $is_alive = false;
            $error = "Connection Failed: " . $e->getMessage();
            if ($connection_time == 0) $connection_time = round((microtime(true) - $start) * 1000, 2);
        }
        $total_time = round((microtime(true) - $start) * 1000, 2);
        
        $stato = ($total_time < 500) ? "VERDE" : (($total_time <= 2000) ? "GIALLO" : "ROSSO");
        if (!$is_alive) $stato = "OFFLINE";
        if ($is_alive && !$deep_test) $stato = "GIALLO"; // Connesso ma query profonda fallita

        return [
            'is_alive' => $is_alive,
            'connection_time_ms' => $connection_time,
            'query_time_ms' => $query_time,
            'total_time_ms' => $total_time,
            'stato' => $stato,
            'deep_test' => $deep_test,
            'errore' => $error
        ];
    }

    public function pingSapSOAP() {
        $url = "http://192.168.10.44/wsToSAP/B1Sync.asmx?reqType=get&objType=ping";
        $xml_data = '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
   <soapenv:Header/>
   <soapenv:Body>
      <loc:BOsync>
         <loc:reqType>get</loc:reqType>
         <loc:objType>ping</loc:objType>
         <loc:docXml>&lt;BOM&gt;&lt;BO&gt;&lt;AdmInfo&gt;&lt;requestUser&gt;manager&lt;/requestUser&gt;&lt;/AdmInfo&gt;&lt;/BO&gt;&lt;/BOM&gt;</loc:docXml>
      </loc:BOsync>
   </soapenv:Body>
</soapenv:Envelope>';

        $headers = array(
            "Content-Type: text/xml; charset=utf-8",
            "SOAPAction: \"http://localhost/BOsync\"",
            "Content-length: ".strlen($xml_data)
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_data);

        $start = microtime(true);
        $res = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $ms = round((microtime(true) - $start) * 1000, 2);
        $isAlive = ($info['http_code'] > 0);

        return [
            'is_alive' => $isAlive,
            'total_time_ms' => $ms
        ];
    }

    public function getCombinedSystemHealth() {
        $web = $this->pingWebService();
        $db = $this->pingDatabase();
        $soap = $this->pingSapSOAP();
        
        $worst_ping = max($web['total_time_ms'], $db['total_time_ms'], $soap['total_time_ms']);
        $is_alive = $web['is_alive'] && $db['is_alive'] && $soap['is_alive'];
        
        if ($db['is_alive'] && !$db['deep_test'] && $worst_ping < 2000) {
            $worst_ping = 2500; // Forziamo rosso se DB è timeoutato/bloccato pesantemente su query
        }

        if (!$is_alive) {
            $worst_ping = 9999;
        }

        return [
            'is_alive' => $is_alive,
            'total_time_ms' => $worst_ping,
            'stato' => ($worst_ping > 2000) ? "ROSSO" : (($worst_ping > 200) ? "GIALLO" : "VERDE"),
            'web' => $web,
            'db' => $db,
            'soap' => $soap
        ];
    }

    public function gestisciStatoServer($stats = null) {
        if ($stats === null) {
            $stats = $this->getCombinedSystemHealth();
        }
        // --- ESCAPE DI EMERGENZA: Se SQL è giù, esci subito con grazia ---
        if ($this->connectionError) {
            echo "⚠️ EMERGENZA: Il server SQL non risponde (Aggiornamenti in corso?). Salto controllo.<br>";
            return false;
        }
        
        $ping = $stats['total_time_ms'];
        if (!$stats['is_alive']) { $ping = 9999; }
        
        // Logga sempre ed a prescindere la telemetria in formato JSON per il Grafico (Separato dal DB)
        $this->logTelemetry($stats);
        
        $final_label = "";
        $ps_info = "Check non eseguito";
        
        switch (true) {
            case ($ping > 2000):
                echo "2. AZIONE: Stato CRITICO (ROSSO). Avvio Manovra Rossa...<br>";
                
                // --- TRIGGER WHATSAPP (Anti-Spam) ---
                if ($this->checkAntiSpam('ROSSO')) {
                    $msg = "SAP MONITOR - STATO: CRITICO - Ping: {$ping} ms. Avvio manovra di ripristino SAP-IIS.";
                    $res_wa = $this->sendWhatsAppAlert($msg);
                    echo "   INFO: Notifica WhatsApp ROSSA: " . strip_tags($res_wa) . "<br>";
                }

                $id_log = $this->iniziaLog('ROSSO', $stats, 'Recupero di Emergenza SAP/SQL');
                $res_job = $this->runSqlReset();
                
                if (strpos($res_job, 'ERRORE') !== false) {
                    $this->chiudiLog($id_log, $ping, false, "Timeout SQL", "FALLITO");
                    return false;
                }
                
                $this->aggiornaStatoJob($id_log, true);
                echo "   Reset inviato. Pausa di 3 minuti...<br>";
                sleep(180);
                
                $post_stats = $this->getCombinedSystemHealth();
                $ps_info = $this->getSystemUptimeCheck();
                $ms_post = $post_stats['total_time_ms'];
                $is_success = ($post_stats['is_alive'] === true && $ms_post < 2000);
                
                if ($is_success) {
                    $final_label = ($ms_post < 200) ? "VERDE" : "RIPRISTINATO";
                    // --- TRIGGER WHATSAPP RECOVERY ---
                    if ($this->checkAntiSpam('VERDE')) {
                        $msg = "SAP MONITOR - STATO: OPERATIVO. Sistema ripristinato (Ping: {$ms_post} ms).";
                        $res_wa = $this->sendWhatsAppAlert($msg);
                        echo "   INFO: Notifica WhatsApp GREEN: " . strip_tags($res_wa) . "<br>";
                    }
                } else {
                    $final_label = "FALLITO";
                }
                
                $this->chiudiLog($id_log, $ms_post, $is_success, $ps_info, $final_label);
                // Il sistema ha eseguito il ripristino, ma per sicurezza usciamo e aspettiamo il prossimo cron
                return false; 
                
            case ($ping >= 200 && $ping <= 2000):
                echo "2. AZIONE: Latenza rilevata (GIALLO). Eseguo Pulizia...<br>";
                
                // --- TRIGGER WHATSAPP WARNING ---
                if ($this->checkAntiSpam('GIALLO')) {
                    $msg = "SAP MONITOR - STATO: LENTEZZA - Ping: {$ping} ms. Eseguita pulizia sessioni DB MSSQL.";
                    $res_wa = $this->sendWhatsAppAlert($msg);
                    echo "   INFO: Notifica WhatsApp GIALLA: " . strip_tags($res_wa) . "<br>";
                }

                $id_log = $this->iniziaLog('GIALLO', $stats, 'Pulizia Connessioni Database');
                $res_cleanup = $this->runSqlCleanup();
                $this->aggiornaStatoJob($id_log, (strpos($res_cleanup, 'ERRORE') === false));
                
                $post_stats = $this->getCombinedSystemHealth();
                $ps_info = $this->getSystemUptimeCheck();
                $final_label = $post_stats['stato'];
                
                $this->chiudiLog($id_log, $post_stats['total_time_ms'], true, $ps_info, $final_label);
                // Abbiamo pulito il DB, ma fermiamo l'esecuzione attuale per prudenza
                return false; 
                
            default:
                echo "2. INFO: Sistema operativo.<br>";
                // Assicuriamoci che anti-spam sappia che siamo in VERDE per resettare i futuri alert
                $this->checkAntiSpam('VERDE');
                return true;
        }
    }
    
    public function iniziaLog($colore, $ping_or_stats, $azione) {
        $web_ping = 0; $db_ping = 0; $soap_ping = 0; $ping = 0;
        if (is_array($ping_or_stats)) {
            $web_ping = cloneValue($ping_or_stats['web']['total_time_ms'] ?? 0);
            $db_ping = cloneValue($ping_or_stats['db']['total_time_ms'] ?? 0);
            $soap_ping = cloneValue($ping_or_stats['soap']['total_time_ms'] ?? 0);
            $ping = $ping_or_stats['total_time_ms'] ?? 0;
        } else {
            $ping = $ping_or_stats;
        }

        $sql = "INSERT INTO log_ws_sap (data_check, ping_result, ping_delay, db_ping_delay, soap_ping_delay, action, result) VALUES (CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, 'IN CORSO')";
        $this->dbLocal->insCrm($sql, [$colore, (float)$ping, (float)$db_ping, (float)$soap_ping, $azione]);
        $res = $this->dbLocal->select("SELECT MAX(id) as last_id FROM log_ws_sap");
        return $res[0]['last_id'] ?? 0;
    }
    
    public function aggiornaStatoJob($id, $successo) {
        $stato = $successo ? 'SENT' : 'ERROR';
        $this->dbLocal->insCrm("UPDATE log_ws_sap SET job_sql_status = ? WHERE id = ?", [$stato, $id]);
    }
    
    public function chiudiLog($id_log, $post_ping, $success, $ps_status = null, $post_result = null) {
        $status = $success ? 'COMPLETATO' : 'FALLITO';
        $sql = "UPDATE log_ws_sap SET post_ping_delay = ?, post_ps_check = ?, post_ping_result = ?, result = ? WHERE id = ?";
        $this->dbLocal->insCrm($sql, [
            (float)$post_ping,
            is_array($ps_status) ? json_encode($ps_status) : (string)$ps_status,
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

    public function runIisReset() {
        try {
            if(!$this->sapModel) return "ERRORE: Modello SQL non inizializzato";
            // Eseguiamo iisreset tramite xp_cmdshell. 
            // Nota: Potrebbe richiedere privilegi elevati configurati sull'utente del servizio SQL.
            $this->sapModel->updCrm("EXEC xp_cmdshell 'iisreset /restart'");
            return "Comando iisreset inviato con successo";
        } catch (Exception $e) { 
            return "ERRORE: " . $e->getMessage(); 
        }
    }
    
    public function getSystemUptimeCheck() {
        $sql = "EXEC xp_cmdshell 'powershell -NoProfile -Command \"Get-CimInstance Win32_Process | Where-Object { \$_.Name -match ''B1_DIServer|w3wp'' } | Select-Object Name, CreationDate | Format-Table -HideTableHeaders\"'";
        try {
            if(!$this->sapModel) return ['error' => "Modello SQL non connesso"];
            $res = $this->sapModel->select($sql);
            
            $groups = [
                'SAP' => ['instances' => [], 'label' => 'SAP Business One', 'icon' => '🖥️'],
                'IIS' => ['instances' => [], 'label' => 'IIS Worker Processes', 'icon' => '🌐']
            ];

            if (is_array($res)) {
                foreach($res as $row) {
                    $line = trim($row['output'] ?? '');
                    if (empty($line)) continue;

                    // Regex per catturare Nome e Data dall'output tabellare di PowerShell
                    if (preg_match('/^(B1_DIServer\.exe|w3wp\.exe)\s+(.*)$/i', $line, $matches)) {
                        $name = $matches[1];
                        $start = trim($matches[2]);
                        $type = (stripos($name, 'B1_DIServer') !== false) ? 'SAP' : 'IIS';
                        $groups[$type]['instances'][] = ['name' => $name, 'start' => $start];
                    }
                }
            }
            
            return $groups;
        } catch (Exception $e) {
            return ['error' => "Errore SQL Bridge: " . $e->getMessage()];
        }
    }
    
    protected function executeCurl($ch) {
        $res = curl_exec($ch);
        $info = curl_getinfo($ch);
        return ['result' => $res, 'info' => $info];
    }

    public function sendWhatsAppAlert($message) {
        // Rimuoviamo backtick se presenti per il messaggio WhatsApp (CallMeBot potrebbe non gradirli)
        $message = str_replace('`n', "\n", $message);
        
        if (!defined('WHATSAPP_PHONE') || !defined('WHATSAPP_API_KEY') || WHATSAPP_API_KEY === 'XXXXXX') {
            $this->logger->log("WhatsApp Alert: Credenziali non configurate in config.php");
            return "Errore: Credenziali non configurate";
        }

        $params = [
            'phone' => WHATSAPP_PHONE,
            'text'  => $message,
            'apikey' => WHATSAPP_API_KEY
        ];
        $url = "https://api.callmebot.com/whatsapp.php?" . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        // User-Agent e Referer obbligatori per non essere bloccati dal loro Apache con 403
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.google.com');
        
        // Bypass verifica SSL (Necessario su Laragon/Windows se i certificati non sono OK)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $curlData = $this->executeCurl($ch);
        $res = $curlData['result'];
        
        if ($res === false) {
            $error = curl_error($ch);
            if ($this->logger) $this->logger->log("WhatsApp Alert Error: " . $error);
            curl_close($ch);
            return "Errore cURL: " . $error;
        }
        
        curl_close($ch);
        if ($this->logger) $this->logger->log("WhatsApp Alert Response: " . $res);
        return $res;
    }

    private function checkAntiSpam($newState) {
        $file = PROJECT_ROOT_PATH . 'guardian/data/state.json';
        $data = ['last_time' => 0, 'last_state' => 'GREEN'];
        
        if (file_exists($file)) {
            $json = file_get_contents($file);
            if ($json) $data = json_decode($json, true);
        }
        
        $now = time();
        $isRosso = ($newState === 'ROSSO');
        $isGiallo = ($newState === 'GIALLO');
        $isVerde = ($newState === 'VERDE' || $newState === 'OFFLINE'); // OFFLINE lo trattiamo come fine dell'azione per ora
        
        $shouldSend = false;

        // 1. Caso ROSSO: Notifica se cambia da NON-ROSSO a ROSSO, o ogni 60 min
        if ($isRosso) {
            if ($data['last_state'] !== 'ROSSO' || ($now - $data['last_time'] > 3600)) {
                $shouldSend = true;
            }
        }
        // 2. Caso RIPRISTINO: Notifica se cambiamo da ROSSO a VERDE
        elseif ($isVerde && $data['last_state'] === 'ROSSO') {
            $shouldSend = true;
        }
        // 3. Caso GIALLO: Notifica se persiste da più di 1 ora o se è nuovo
        elseif ($isGiallo) {
            if ($data['last_state'] !== 'GIALLO' || ($now - $data['last_time'] > 3600)) {
                $shouldSend = true;
            }
        }

        if ($shouldSend) {
            $data['last_time'] = $now;
            $data['last_state'] = $newState;
            file_put_contents($file, json_encode($data), LOCK_EX);
            return true;
        }
        
        // Se lo stato è cambiato ma non dobbiamo avvisare (es. da GIALLO a VERDE), 
        // aggiorniamo comunque lo stato interno per la prossima volta
        if ($data['last_state'] !== $newState) {
            $data['last_state'] = $newState;
            file_put_contents($file, json_encode($data), LOCK_EX);
        }
        
        return false;
    }

    /**
     * Logga silenziosamente la telemetria per il grafico in un JSON dedicato.
     * Salva fino a 96 records (48 log/giorno x 2 giorni) e scollega la telemetria dal DB.
     */
    private function logTelemetry($stats) {
        $file = PROJECT_ROOT_PATH . 'guardian/data/telemetry.json';
        $now = time();
        $history = [];
        
        if (file_exists($file)) {
            $json = file_get_contents($file);
            $history = json_decode($json, true) ?: [];
        }
        
        // Evitiamo spam se si testano script in loop manualmente, permettiamo 1 entry ogni 15 minuti.
        // Ma se lo stato NON e' verde, forziamo sempre l'inserimento per evidenziare il problema.
        $stato = $stats['is_alive'] ? $stats['stato'] : 'OFFLINE';
        $last_time = end($history)['timestamp'] ?? 0;
        
        if ($stato === 'VERDE' && ($now - $last_time < 900)) {
            return;
        }

        $history[] = [
            'timestamp' => $now,
            'date' => date('d/m H:i', $now),
            'web' => round($stats['web']['total_time_ms'] ?? 0, 1),
            'db' => round($stats['db']['total_time_ms'] ?? 0, 1),
            'soap' => round($stats['soap']['total_time_ms'] ?? 0, 1),
            'status' => $stato
        ];

        // Manteniamo esattamente 96 log (2 giorni)
        if (count($history) > 96) {
            $history = array_slice($history, -96);
        }

        file_put_contents($file, json_encode($history), LOCK_EX);
    }
}
function cloneValue($val) { return $val; }