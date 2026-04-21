<?php
include_once 'SapInvoiceHandler.php';

/**
 * SapDiagnosticHandler estende la logica di base per fornire monitoraggio avanzato.
 */
class SapDiagnosticHandler extends SapServiceHandler {
    

    /**
     * Recupera gli ultimi N log degli interventi con supporto filtri
     */
    public function getRecentLogs($limit = 10, $filters = []) {
        try {
            $where = " WHERE 1=1 ";
            $params = [];
            
            if (!empty($filters['date'])) {
                $where .= " AND data_check LIKE ? ";
                $params[] = $filters['date'] . '%';
            }
            
            if (!empty($filters['result']) && $filters['result'] !== 'Tutti') {
                $status_map = ['Successo' => 'COMPLETATO', 'Fallito' => 'FALLITO'];
                $target = $status_map[$filters['result']] ?? $filters['result'];
                $where .= " AND result = ? ";
                $params[] = $target;
            }

            $sql = "SELECT id, data_check, action, ping_result, ping_delay, result, post_ping_result 
                    FROM log_ws_sap " . $where . "
                    ORDER BY id DESC 
                    LIMIT " . (int)$limit;
            
            return $this->dbLocal->select($sql, $params);
            return $this->dbLocal->select($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Esegue la disamina completa e restituisce i dati per la UI
     */
    public function getFullDiagnostics($filters = []) {
        $stats_http = $this->pingWebService();
        $stats_db = $this->pingDatabase();
        $uptime = $this->getSystemUptimeCheck();
        
        $logs_filtered = $this->getRecentLogs(15, $filters);
        $logs_chart = $this->getRecentLogs(20); 
        
        return [
            'http' => $stats_http,
            'db' => $stats_db,
            'uptime' => $uptime,
            'logs' => $logs_filtered,
            'chart_logs' => $logs_chart
        ];
    }
}
