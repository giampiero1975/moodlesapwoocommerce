<?php
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', dirname(__DIR__, 2) . '/');
}

// Definiamo una costante per usare un file di stato di test
define('TEST_STATE_FILE', PROJECT_ROOT_PATH . 'guardian/data/state_test.json');

require_once PROJECT_ROOT_PATH . 'Model/SapInvoiceHandler.php';

class SapEscalationTester extends SapServiceHandler {
    public $actions = [];
    public $telegram_logs = [];
    
    // Override del costruttore per evitare connessioni reali
    public function __construct() {
        $this->connectionError = false;
    }

    // Mock dei metodi distruttivi/esterni
    public function runSqlCleanup() {
        $this->actions[] = "SQL_CLEANUP";
        return "SUCCESSO";
    }

    public function runSqlReset() {
        $this->actions[] = "SQL_RESET";
        return "Segnale inviato";
    }

    public function runIisReset() {
        $this->actions[] = "IIS_RESET";
        return "Comando inviato";
    }

    public function sendTelegramAlert($message) {
        $this->telegram_logs[] = $message;
        return "MOCK_OK";
    }

    public function iniziaLog($colore, $stats, $azione) { return 123; }
    public function aggiornaStatoJob($id, $successo) {}
    public function chiudiLog($id_log, $post_ping, $success, $ps_status = null, $post_result = null) {}
    
    // Bypassiamo il log della telemetria per non sporcare i file reali
    protected function logTelemetry($stats) {}

    // Mock del ping post-reset per simulare ripristino
    public function getCombinedSystemHealth() {
        return [
            'is_alive' => true,
            'total_time_ms' => 50.0,
            'stato' => 'VERDE'
        ];
    }
    
    public function getSystemUptimeCheck() { return "Mock uptime"; }

    // Dobbiamo sovrascrivere il percorso del file di stato nel metodo originale
    // Ma siccome il metodo gestisciStatoServer usa una variabile locale, dobbiamo mockare l'intero metodo o 
    // assicurarci che il file puntato sia quello di test.
    // Invece di modificare il codice originale, emuliamo l'ambiente.
    
    public function gestisciStatoServer($stats = null) {
        // Redirigiamo state.json a state_test.json prima di chiamare il parent? 
        // Purtroppo $stateFile è hardcoded nel metodo. 
        // Quindi dobbiamo copiare il metodo qui e cambiare solo il percorso file.
        
        if ($stats === null) { $stats = $this->getCombinedSystemHealth(); }
        if ($this->connectionError) return false;
        
        $ping = $stats['total_time_ms'];
        if (!$stats['is_alive']) { $ping = 9999; }
        
        $this->logTelemetry($stats);
        
        // --- TEST PATH ---
        $stateFile = TEST_STATE_FILE;
        $stateData = ['last_time' => 0, 'last_state' => 'GREEN', 'consecutive_giallo' => 0];
        if (file_exists($stateFile)) {
            $json = file_get_contents($stateFile);
            if ($json) $stateData = array_merge($stateData, json_decode($json, true));
        }
        $consecutiveGiallo = intval($stateData['consecutive_giallo'] ?? 0);
        
        switch (true) {
            case ($ping > 3000):
                $this->actions[] = "TRIGGER_ROSSO_DIRETTO";
                $stateData['consecutive_giallo'] = 0;
                file_put_contents($stateFile, json_encode($stateData), LOCK_EX);
                if ($this->checkAntiSpamMock('ROSSO', $stateFile)) {
                    $this->sendTelegramAlert("SAP MONITOR - STATO: CRITICO - Ping: {$ping} ms. Avvio manovra...");
                }
                $this->runSqlReset();
                $this->runIisReset();
                return true;

            case ($ping >= 800 && $ping <= 3000):
                $consecutiveGiallo++;
                $stateData['consecutive_giallo'] = $consecutiveGiallo;
                file_put_contents($stateFile, json_encode($stateData), LOCK_EX);
                
                if ($consecutiveGiallo >= 5) {
                    $this->actions[] = "TRIGGER_ESCALATION";
                    if ($this->checkAntiSpamMock('ROSSO', $stateFile)) {
                        $this->sendTelegramAlert("SAP MONITOR - CRITICO: ESCALATION ({$consecutiveGiallo} cicli). Reset totale.");
                    }
                    $this->runSqlReset();
                    $this->runIisReset();
                    $stateData['consecutive_giallo'] = 0;
                    file_put_contents($stateFile, json_encode($stateData), LOCK_EX);
                    return true;
                }
                
                $this->actions[] = "TRIGGER_GIALLO_PULIZIA";
                if ($this->checkAntiSpamMock('GIALLO', $stateFile)) {
                    $this->sendTelegramAlert("SAP MONITOR - STATO: LENTEZZA - Ping: {$ping} ms.");
                }
                $this->runSqlCleanup();
                return true;
                
            default:
                $this->actions[] = "TRIGGER_VERDE";
                $stateData['consecutive_giallo'] = 0;
                file_put_contents($stateFile, json_encode($stateData), LOCK_EX);
                $this->checkAntiSpamMock('VERDE', $stateFile);
                return true;
        }
    }

    // Mock della logica antispam per usare il file di test
    private function checkAntiSpamMock($newState, $file) {
        $data = ['last_time' => 0, 'last_state' => 'GREEN'];
        if (file_exists($file)) {
            $json = file_get_contents($file);
            if ($json) $data = array_merge($data, json_decode($json, true));
        }
        $now = time();
        $shouldSend = false;
        if ($newState === 'ROSSO') {
            if ($data['last_state'] !== 'ROSSO' || ($now - $data['last_time'] > 3600)) $shouldSend = true;
        } elseif ($newState === 'GIALLO') {
            if ($data['last_state'] !== 'GIALLO' || ($now - $data['last_time'] > 3600)) $shouldSend = true;
        } elseif ($newState === 'VERDE' && $data['last_state'] === 'ROSSO') {
            $shouldSend = true;
        }
        if ($shouldSend) {
            $data['last_time'] = $now;
            $data['last_state'] = $newState;
            file_put_contents($file, json_encode($data), LOCK_EX);
            return true;
        }
        if ($data['last_state'] !== $newState) {
            $data['last_state'] = $newState;
            file_put_contents($file, json_encode($data), LOCK_EX);
        }
        return false;
    }
}

// --- ESECUZIONE TEST ---
echo "--- INIZIO VERIFICA LOGICA ESCALATION ---\n";

if (file_exists(TEST_STATE_FILE)) unlink(TEST_STATE_FILE);

$tester = new SapEscalationTester();

// 1. Check VERDE (Inizio pulito)
echo "\n[Test 1] Invio stato VERDE...\n";
$tester->gestisciStatoServer(['is_alive' => true, 'total_time_ms' => 50, 'stato' => 'VERDE']);
echo "Azioni: " . implode(", ", $tester->actions) . "\n";
echo "Telegram inviati: " . count($tester->telegram_logs) . "\n";

// 2. Check GIALLO 1 (Cleanup)
echo "\n[Test 2] Invio stato GIALLO (1/5)...\n";
$tester->actions = []; $tester->telegram_logs = [];
$tester->gestisciStatoServer(['is_alive' => true, 'total_time_ms' => 950, 'stato' => 'GIALLO']);
echo "Azioni: " . implode(", ", $tester->actions) . "\n";
echo "Telegram inviati: " . count($tester->telegram_logs) . " -> " . ($tester->telegram_logs[0] ?? '') . "\n";

// 3. Check GIALLO 2 (Cleanup, No Telegram causa cooldown 1h)
echo "\n[Test 3] Invio stato GIALLO (2/5)...\n";
$tester->actions = []; $tester->telegram_logs = [];
$tester->gestisciStatoServer(['is_alive' => true, 'total_time_ms' => 955, 'stato' => 'GIALLO']);
echo "Azioni: " . implode(", ", $tester->actions) . "\n";
echo "Telegram inviati: " . count($tester->telegram_logs) . " (Corretto: cooldown attivo)\n";

// 4. Check GIALLO 5 (ESCALATION RESET)
echo "\n[Test 4] Invio stato GIALLO (5/5 - ESCALATION)...\n";
$tester->actions = []; $tester->telegram_logs = [];
// Simuliamo l'avanzamento del contatore
$state = json_decode(file_get_contents(TEST_STATE_FILE), true);
$state['consecutive_giallo'] = 4;
file_put_contents(TEST_STATE_FILE, json_encode($state));

$tester->gestisciStatoServer(['is_alive' => true, 'total_time_ms' => 960, 'stato' => 'GIALLO']);
echo "Azioni: " . implode(", ", $tester->actions) . "\n";
echo "Telegram inviati: " . count($tester->telegram_logs) . " -> " . ($tester->telegram_logs[0] ?? '') . "\n";

// 5. Verifica reset contatore dopo escalation (Check VERDE)
echo "\n[Test 5] Verifica reset contatore (Invio VERDE)...\n";
$tester->actions = []; $tester->telegram_logs = [];
$tester->gestisciStatoServer(['is_alive' => true, 'total_time_ms' => 40, 'stato' => 'VERDE']);
$state = json_decode(file_get_contents(TEST_STATE_FILE), true);
echo "Contatore consecutivo: " . $state['consecutive_giallo'] . " (Atteso: 0)\n";

echo "\n--- FINE TEST ---\n";
unlink(TEST_STATE_FILE);
