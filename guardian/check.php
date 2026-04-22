<?php
// guardian/check.php
// Firewall Modulo Indipendente Guardiano SAP

if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', dirname(__DIR__) . '/');
}

require_once PROJECT_ROOT_PATH . 'Model/SapDiagnosticHandler.php';

$sapHandler = new SapDiagnosticHandler();
$stats = $sapHandler->getCombinedSystemHealth();

if (!$sapHandler->gestisciStatoServer($stats)) {
    // Interruzione ad alto livello. Impediamo sprechi di RAM o connessioni al DB.
    // L'orchestratore ha già sparato i log e gli avvisi WhatsApp all'interno di gestisciStatoServer()
    exit("🛑 GUARDIANO: Sistema B1Sync in stato critico. Pipeline fatture interrotta per sicurezza.\n");
}

// Altrimenti procediamo silenziosamente
?>
