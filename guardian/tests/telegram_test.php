<?php
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', __DIR__ . '/../../');
}

require_once PROJECT_ROOT_PATH . 'inc/config.php';
require_once PROJECT_ROOT_PATH . 'Model/SapInvoiceHandler.php';

echo "--- TEST INVIO TELEGRAM ---\n";

$handler = new SapServiceHandler();
$message = "🛡️ SAP GUARDIAN TEST\nMessaggio di prova per verifica integrità canale Telegram.\nData: " . date('Y-m-d H:i:s');

echo "Invio messaggio...\n";
$result = $handler->sendTelegramAlert($message);

echo "Risultato API:\n";
print_r($result);
echo "\n--- FINE TEST ---\n";
