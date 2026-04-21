<?php
/**
 * AUDIT DI PRODUZIONE SAP GUARDIAN 2.0
 * Questo script verifica solo la salute del sistema di monitoraggio.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('PROJECT_ROOT_PATH', __DIR__ . '/');
require_once 'inc/config.php';
require_once 'Model/SapDiagnosticHandler.php';

echo "<h1>🔍 Audit Salute SAP Guardian</h1>";

// 1. Verifica Costanti
echo "<h3>1. Verifica Credenziali</h3>";
$has_phone = defined('WHATSAPP_PHONE') ? "✅ OK" : "❌ MANCANTE";
$has_key = (defined('WHATSAPP_API_KEY') && WHATSAPP_API_KEY !== 'XXXXXX') ? "✅ OK" : "❌ MANCANTE";
echo "WhatsApp Phone: $has_phone<br>";
echo "WhatsApp Key: $has_key<br>";

// 2. Verifica Cartella Logs
echo "<h3>2. Verifica Permessi File System</h3>";
$logs_dir = PROJECT_ROOT_PATH . 'logs';
if (!file_exists($logs_dir)) {
    echo "❌ CARTELLA 'logs' NON TROVATA. Creala sul server per far funzionare l'Anti-Spam.<br>";
} else {
    $is_writable = is_writable($logs_dir) ? "✅ SCRIVIBILE" : "❌ NON SCRIVIBILE (Controlla permessi Linux)";
    echo "Cartella Logs: $is_writable<br>";
}

// 3. Test Connessione Web (Senza invio alert)
echo "<h3>3. Test Connessione a SAP</h3>";
$sap = new SapDiagnosticHandler();
$ping = $sap->pingWebService();
echo "Stato Web: " . $ping['stato'] . " (" . $ping['total_time_ms'] . "ms)<br>";

echo "<hr><p>💡 <b>Consiglio:</b> Se vuoi forzare un WhatsApp ora, cancella il file <code>logs/last_whatsapp_alert.json</code> o simula un ping di 3500ms in setInvoiceCurl.php</p>";
