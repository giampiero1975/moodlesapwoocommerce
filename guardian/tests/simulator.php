<?php
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', dirname(__DIR__, 2) . '/');
}
require_once PROJECT_ROOT_PATH . 'Model/SapDiagnosticHandler.php';

class MockSapDiagnosticHandler extends SapDiagnosticHandler {
    
    public function runSqlReset() {
        echo "<div style='color:red; font-weight:bold;'>[MOCK-INTERVENTO] Esecuzione Batch Recupero SAP EVITATO (Simulato).</div>";
        return "COMPLETATO";
    }
    
    public function runSqlCleanup() {
        echo "<div style='color:orange; font-weight:bold;'>[MOCK-INTERVENTO] Esecuzione Pulizia Query Sql pendenti EVITATA (Simulato).</div>";
        return "COMPLETATO";
    }
    
    public function sendWhatsAppAlert($message) {
        echo "<div style='color:green; font-weight:bold;'>[MOCK-WHATSAPP] Intercettato: $message</div>";
        return "Test Inviato";
    }
    
    public function iniziaLog($action_type, $stats, $action_name) {
        echo "<div style='color:gray;'>[MOCK-DB] Registrato Storico Intervento: $action_type -> $action_name</div>";
        return 999;
    }
    
    public function chiudiLog($id_log, $post_ping, $success, $ps_status = null, $post_result = null) {
        echo "<div style='color:gray;'>[MOCK-DB] Chiusura Log con stato finale: $post_result ($post_ping ms)</div><br>";
    }
    
    // Per bypassare la logica antispam che ci impedirebbe 4 messaggi test consecutivi
    protected function checkAntiSpam($type) { return true; }
    
    // Per test fluidi ignoriamo i log Json del grafico
    private function logTelemetry($stats) { return true; }
    
    // Mockiamo il riavvio che farebbe 3 minuti di sleep nella realtà
    public function gestisciStatoServer($stats = null) {
        if ($this->connectionError) {
            echo "⚠️ EMERGENZA: Il server SQL non risponde.<br>";
            return false;
        }
        
        $ping = $stats['total_time_ms'];
        if (!$stats['is_alive']) { $ping = 9999; }
        
        $final_label = "";
        
        switch (true) {
            case ($ping > 2000):
                echo "🛑 AZIONE: TIMEOUT CRITICO RILEVATO ({$ping}ms). Eseguo Recupero di Emergenza...<br>";
                if ($this->checkAntiSpam('ROSSO')) {
                    $msg = "SAP MONITOR - STATO: CRITICO - Ping: {$ping} ms. Avvio manovra di ripristino SAP-IIS.";
                    $res_wa = $this->sendWhatsAppAlert($msg);
                }
                $id_log = $this->iniziaLog('ROSSO', $stats, 'Recupero di Emergenza SAP/SQL');
                $res_job = $this->runSqlReset();
                
                echo "   <span style='color:gray'>Reset inviato. Pausa di 3 minuti... (Saltata nel Simulator)</span><br>";
                // Mock Finale
                $is_success = true; 
                $ms_post = 80; // Post-recupero simulato a 80ms
                $final_label = "VERDE (Simulato)";
                
                if ($this->checkAntiSpam('VERDE')) {
                    $msg = "SAP MONITOR - STATO: OPERATIVO. Sistema ripristinato (Ping: {$ms_post} ms).";
                    $res_wa = $this->sendWhatsAppAlert($msg);
                }
                $this->chiudiLog($id_log, $ms_post, $is_success, 'Mock ps check', $final_label);
                return $is_success;
                
            case ($ping >= 200 && $ping <= 2000):
                echo "⚠️ AZIONE: Latenza rilevata ({$ping}ms - GIALLO). Eseguo Pulizia Database...<br>";
                if ($this->checkAntiSpam('GIALLO')) {
                    $msg = "SAP MONITOR - STATO: LENTEZZA - Ping: {$ping} ms. Eseguita pulizia sessioni DB MSSQL.";
                    $res_wa = $this->sendWhatsAppAlert($msg);
                }
                $id_log = $this->iniziaLog('GIALLO', $stats, 'Pulizia Connessioni Database');
                $res_cleanup = $this->runSqlCleanup();
                
                $final_label = 'VERDE (Simulato)';
                $this->chiudiLog($id_log, 80, true, 'Mock ps check', $final_label);
                return true;
                
            default:
                echo "✅ AZIONE: Nessun intervento effettuato. Sistema fluido (Peggior Ping: {$ping} ms).<br>";
                $this->checkAntiSpam('VERDE');
                return true;
        }
    }
}

$simulator = new MockSapDiagnosticHandler();

$cases = [
    [
        'title' => 'CASO 1: SISTEMA PERFETTAMENTE SANO (Tutto verde)',
        'stats' => [ 'is_alive' => true, 'total_time_ms' => 50.5, 'stato' => 'VERDE', 'web' => ['total_time_ms' => 15.2], 'db' => ['total_time_ms' => 40.1, 'deep_test' => true], 'soap' => ['total_time_ms' => 50.5] ]
    ],
    [
        'title' => 'CASO 2: LENTEZZA DATABASE O SOAP (Giallo: Latenza tra 200ms e 2000ms)',
        'stats' => [ 'is_alive' => true, 'total_time_ms' => 450.0, 'stato' => 'GIALLO', 'web' => ['total_time_ms' => 15.2], 'db' => ['total_time_ms' => 450.0, 'deep_test' => true],  'soap' => ['total_time_ms' => 50.5] ]
    ],
    [
        'title' => 'CASO 3: PARALISI DI UN SERVIZIO (Rosso: Latenza > 2000ms)',
        'stats' => [ 'is_alive' => true, 'total_time_ms' => 3500.0, 'stato' => 'ROSSO', 'web' => ['total_time_ms' => 15.2], 'db' => ['total_time_ms' => 40.1, 'deep_test' => true], 'soap' => ['total_time_ms' => 3500.0] ]
    ],
    [
        'title' => 'CASO 4: CRASH COMPLETO O BLOCCO DI RETE (Sito IIS offline, Latenza infinita)',
        'stats' => [ 'is_alive' => false, 'total_time_ms' => 9999, 'stato' => 'OFFLINE', 'web' => ['total_time_ms' => 0], 'db' => ['total_time_ms' => 0, 'deep_test' => false], 'soap' => ['total_time_ms' => 0] ]
    ]
];

foreach ($cases as $c) {
    echo "<h3>" . $c['title'] . "</h3>\n";
    $simulator->gestisciStatoServer($c['stats']);
    echo "<hr>\n";
}
